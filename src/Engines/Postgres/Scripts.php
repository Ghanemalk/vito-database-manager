<?php

namespace VitoDbManager\Engines\Postgres;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * How psql runs a script, and how its CSV output and its notices are read back.
 *
 * Part of PostgresEngine, which is split by area (see there).
 */
trait Scripts
{
    protected function command(array $o): string
    {
        // a conninfo string, so any database name is read as a name
        $conn = "dbname='".addcslashes($this->database, "'\\")."'";

        return 'cd /tmp 2>/dev/null; exec '.$this->client.' -X -q -v ON_ERROR_STOP=0 --csv -d '.self::sh($conn).' -f -';
    }

    protected function script(array $statements, array $o): string
    {
        $m = $this->marker();
        $out = [];
        $line = 0;
        $add = static function (string $text) use (&$out, &$line): void {
            $out[] = $text;
            $line += substr_count($text, "\n") + 1;
        };
        $check = static function (int $i) use ($add, $m): void {
            $add('\if :ERROR');
            $add("\\echo {$m} X {$i} :SQLSTATE");
            $add('\echo :LAST_ERROR_MESSAGE');
            $add("\\echo {$m} Q");
            $add('\q');
            $add('\endif');
        };
        foreach (['\set ON_ERROR_STOP off', '\set QUIET on', '\set SHOW_CONTEXT errors', '\pset format csv', '\pset tuples_only off',
            '\pset footer off', '\pset null '.$this->nullToken(), '\timing on'] as $setting) {
            $add($setting);
        }
        $reset = ["SET client_encoding = 'UTF8';", 'SET standard_conforming_strings = on;'];
        foreach ($reset as $s) {
            $add($s);
        }
        $add("SET lock_timeout = '".max(1, (int) $o['lock_timeout'])."s';");
        if ($o['readonly']) {
            $add('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;');
        }
        if ($o['tx']) {
            $add('BEGIN;');
        }
        $check(-1);
        $this->lines = [];
        $this->wrapped = [];
        $timeout = null;
        foreach (array_values($statements) as $i => $s) {
            $sql = is_array($s) ? (string) $s['sql'] : $s;
            $t = max(1, (int) (is_array($s) && isset($s['timeout']) ? $s['timeout'] : $o['timeout']));
            if ($t !== $timeout) {
                $add("SET statement_timeout = '{$t}s';");
                $timeout = $t;
            }
            $add("\\echo {$m} B {$i}");
            $wrap = $o['limit'] > 0 && Splitter::isLimitable($sql, Splitter::PGSQL);
            $first = $line + 1;
            $add($wrap ? "SELECT * FROM (\n{$sql}\n) AS vdbm_q LIMIT ".((int) $o['limit'] + 1) : $sql);
            $add(';');
            $this->lines[$i] = [$first, $line];
            $this->wrapped[$i] = $wrap;
            $check($i);
            $add("\\echo {$m} E {$i} :ROW_COUNT");
            if ($o['console']) {
                // psql reads the next statement with the server's current settings; keep them the splitter's
                foreach ($reset as $r) {
                    $add($r);
                }
            }
        }
        if ($o['tx']) {
            $add('COMMIT;');
            $check(-2);
        }
        $add("\\echo {$m} Z");

        return implode("\n", $out)."\n";
    }

    protected function parse(string $stdout, string $stderr, array $o): array
    {
        $m = $this->marker().' ';
        $keep = $o['limit'] > 0 ? (int) $o['limit'] + 1 : PHP_INT_MAX;
        $marks = [];
        $pos = 0;
        while (($p = strpos($stdout, $m, $pos)) !== false) {
            $eol = strpos($stdout, "\n", $p);
            $eol = $eol === false ? strlen($stdout) : $eol;
            if ($p === 0 || $stdout[$p - 1] === "\n") {
                $marks[] = [$p, $eol, explode(' ', substr($stdout, $p + strlen($m), $eol - $p - strlen($m)))];
            }
            $pos = $eol;
        }
        $results = [];
        $error = null;
        $completed = false;
        $cur = null;
        $from = 0;
        foreach ($marks as $k => [$start, $eol, $tok]) {
            $kind = $tok[0] ?? '';
            if ($kind === 'B') {
                $cur = (int) ($tok[1] ?? 0);
                $from = $eol + 1;
            } elseif ($kind === 'E' && $cur !== null) {
                [$set, $ms] = $this->region(substr($stdout, $from, max(0, $start - $from)), $keep);
                $rc = $tok[2] ?? '';
                $results[$cur] = [
                    'sets' => $set === null ? [] : [$set],
                    'affected' => ctype_digit($rc) ? (int) $rc : null,
                    'insert_id' => null,
                    'ms' => $ms,
                    'warnings' => [],
                ];
                $cur = null;
            } elseif ($kind === 'X') {
                $next = $marks[$k + 1][0] ?? strlen($stdout);
                $error = [
                    'i' => (int) ($tok[1] ?? -1),
                    'code' => null,
                    'sqlstate' => ($tok[2] ?? '') !== '' ? $tok[2] : null,
                    'message' => rtrim(substr($stdout, $eol + 1, max(0, $next - $eol - 1)), "\n"),
                ];
                break;
            } elseif ($kind === 'Z') {
                $completed = true;
            }
        }
        $this->readStderr($stderr, $results, $error);

        return ['results' => $results, 'error' => $error, 'completed' => $completed && $error === null, 'current' => $cur];
    }

    /**
     * Notices and warnings go to the statement whose lines they name; the error gets its DETAIL, HINT and line.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<string, mixed>|null  $error
     */
    private function readStderr(string $stderr, array &$results, ?array &$error): void
    {
        if ($stderr === '' || preg_match_all(
            '/^psql:[^\n]*?:(\d+): (ERROR|FATAL|PANIC|WARNING|NOTICE|INFO|LOG|DEBUG):  (.*?)(?=^psql:|\z)/ms',
            $stderr,
            $mm,
            PREG_SET_ORDER,
        ) === 0) {
            return;
        }
        foreach ($mm as [, $lineNo, $level, $text]) {
            $at = null;
            foreach ($this->lines as $i => [$first, $last]) {
                if ($lineNo >= $first && $lineNo <= $last) {
                    $at = $i;
                    break;
                }
            }
            $parts = preg_split('/\n(?=(?:DETAIL|HINT|CONTEXT|QUERY|LINE \d+):)/', rtrim($text));
            $info = ['message' => array_shift($parts)];
            foreach ($parts as $p) {
                if (preg_match('/^(DETAIL|HINT|CONTEXT):\s+(.*)$/s', $p, $x) === 1) {
                    $info[strtolower($x[1])] = $x[2];
                } elseif (preg_match('/^LINE (\d+):/', $p, $x) === 1) {
                    $info['stmt_line'] = (int) $x[1] - ($at !== null && ($this->wrapped[$at] ?? false) ? 1 : 0);
                }
            }
            if (in_array($level, ['ERROR', 'FATAL', 'PANIC'], true)) {
                if ($error !== null) {
                    $error += array_diff_key($info, ['message' => true]);
                }

                continue;
            }
            if ($at !== null && isset($results[$at])) {
                $results[$at]['warnings'][] = ['level' => strtolower($level), 'code' => null, 'message' => $info['message'],
                    'detail' => $info['detail'] ?? null, 'hint' => $info['hint'] ?? null];
            }
        }
    }

    /**
     * What psql printed for one statement: a CSV result (header and rows) or nothing, then its "Time:" line.
     *
     * @return array{0: ?array{columns: list<string>, rows: list<list<?string>>, more: bool}, 1: ?float}
     */
    private function region(string $body, int $keep): array
    {
        $ms = null;
        $trim = rtrim($body, "\n");
        $nl = strrpos($trim, "\n");
        $last = $nl === false ? $trim : substr($trim, $nl + 1);
        if (preg_match('/^Time: (\d+(?:\.\d+)?) ms/', $last, $m) === 1) {
            $ms = round((float) $m[1], 2);
            $body = $nl === false ? '' : substr($trim, 0, $nl + 1);
        }
        if (trim($body) === '') {
            return [null, $ms];
        }

        return [$this->csv($body, $keep), $ms];
    }

    /**
     * psql's CSV: fields quoted when they hold a comma, quote or line break; NULL printed as our marker.
     *
     * @return array{columns: list<string>, rows: list<list<?string>>, more: bool}
     */
    private function csv(string $s, int $keep): array
    {
        $null = $this->nullToken();
        $len = strlen($s);
        $i = 0;
        $columns = null;
        $rows = [];
        $more = false;
        $row = [];
        while ($i < $len) {
            if ($s[$i] === '"') {
                $j = $i + 1;
                $field = '';
                while (true) {
                    $q = strpos($s, '"', $j);
                    if ($q === false) {
                        $field .= substr($s, $j);
                        $i = $len;
                        break;
                    }
                    $field .= substr($s, $j, $q - $j);
                    if (($s[$q + 1] ?? '') === '"') {
                        $field .= '"';
                        $j = $q + 2;

                        continue;
                    }
                    $i = $q + 1;
                    break;
                }
            } else {
                $n = strcspn($s, ",\n", $i);
                $field = substr($s, $i, $n);
                $i += $n;
                if ($field === $null) {
                    $field = null;
                }
            }
            $row[] = $field;
            $sep = $s[$i] ?? "\n";
            $i++;
            if ($sep === "\n") {
                if ($columns === null) {
                    $columns = array_map('strval', $row);
                } elseif (count($rows) < $keep) {
                    $rows[] = $row;
                } else {
                    $more = true;
                }
                $row = [];
            }
        }

        return ['columns' => $columns ?? [], 'rows' => $rows, 'more' => $more];
    }

    protected function isStaleError(DbmException $e): bool
    {
        return ($e->extra['sqlstate'] ?? null) === '42703';
    }

    protected function guardStatements(array $statements): void
    {
        foreach ($statements as $k => $s) {
            $words = array_column(Splitter::words($s['sql'], Splitter::PGSQL), 0);
            if (($words[0] ?? '') === 'COPY' && array_intersect($words, ['STDIN', 'STDOUT', 'PROGRAM']) !== []) {
                throw new DbmException(sprintf(
                    'Statement %d (line %d): COPY to or from the client or a program is not possible here. Use INSERT or SELECT, or the CSV import.',
                    $k + 1,
                    $s['line']
                ), 'input', ['statement' => $k, 'line' => $s['line']]);
            }
        }
    }
}
