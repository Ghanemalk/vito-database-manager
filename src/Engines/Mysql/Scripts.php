<?php

namespace VitoDbManager\Engines\Mysql;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * How the mysql or mariadb client runs a script, and how its XML output is read back.
 *
 * Part of MysqlEngine, which is split by area (see there).
 */
trait Scripts
{
    protected function command(array $o): string
    {
        $c = $this->client;
        // the client's own safety switches, where this version has them: no shell or file commands from a script
        $probe = 'H=$('.$c.' --help </dev/null 2>/dev/null); F=; '
            .'case "$H" in *--sandbox*) F="--sandbox";; esac; '
            .'case "$H" in *--system-command*) F="$F --skip-system-command";; esac; '
            .($o['hex'] ? 'case "$H" in *--binary-as-hex*) F="$F --binary-as-hex";; esac; ' : '');

        return 'cd /tmp 2>/dev/null; '.$probe.'exec '.$c.' $F --xml --comments --default-character-set=utf8mb4'
            .' --max-allowed-packet=1073741824 --skip-auto-rehash --no-beep --local-infile=0 --database='.self::sh($this->database);
    }

    /**
     * Every statement is framed by two marker rows: 'B' before it and 'E' after it with its row count, insert id,
     * warning count and the time. The delimiter is random, so no statement can end early or run on into ours.
     */
    protected function script(array $statements, array $o): string
    {
        // the client keeps at most 15 characters of a delimiter
        $d = ';;'.substr($this->tag, 0, 10).';;';
        $m = '`__vdbm_'.$this->tag.'`';
        $lines = ['DELIMITER '.$d];
        $add = static function (string $sql) use (&$lines, $d): void {
            $lines[] = $sql."\n".$d;
        };
        $add('SET NAMES utf8mb4');
        $add(self::KEEP_ESCAPES);
        if ($o['fresh_stats'] && ! $this->maria()) {
            $add('SET SESSION information_schema_stats_expiry = 0');
        }
        $add('SET SESSION innodb_lock_wait_timeout = '.max(1, (int) $o['lock_timeout']));
        if ($o['readonly']) {
            $add('SET SESSION TRANSACTION READ ONLY');
        }
        if ($o['tx']) {
            $add('START TRANSACTION');
        }
        $timeout = null;
        $limited = false;
        foreach (array_values($statements) as $i => $s) {
            $sql = is_array($s) ? (string) $s['sql'] : $s;
            $t = max(1, (int) (is_array($s) && isset($s['timeout']) ? $s['timeout'] : $o['timeout']));
            if ($t !== $timeout) {
                // MySQL limits SELECTs only; MariaDB every statement
                $add($this->maria() ? 'SET SESSION max_statement_time = '.$t : 'SET SESSION max_execution_time = '.($t * 1000));
                $timeout = $t;
            }
            if ($o['limit'] > 0) {
                $limit = Splitter::isLimitable($sql, Splitter::MYSQL);
                if ($limit !== $limited) {
                    $add('SET SESSION sql_select_limit = '.($limit ? (int) $o['limit'] + 1 : 'DEFAULT'));
                    $limited = $limit;
                }
            }
            $add("SELECT 'B' AS {$m}, {$i} AS `i`, LAST_INSERT_ID() AS `id`, UNIX_TIMESTAMP(NOW(6)) AS `t`");
            // with --comments the leading comment stays in the client's buffer, so no line of the statement can be
            // taken for one of the client's own commands (use, source, system…)
            $add("/* vdbm:{$i} */ ".$sql);
            $add('GET DIAGNOSTICS @vdbm_rc = ROW_COUNT, @vdbm_w = NUMBER');
            if ($o['warnings']) {
                $add('SHOW WARNINGS');
            }
            $add("SELECT 'E' AS {$m}, {$i} AS `i`, @vdbm_rc AS `rc`, LAST_INSERT_ID() AS `id`, @vdbm_w AS `w`, UNIX_TIMESTAMP(NOW(6)) AS `t`");
            if ($o['console']) {
                $add('SET NAMES utf8mb4');
                $add(self::KEEP_ESCAPES);
            }
        }
        if ($o['tx']) {
            $add('COMMIT');
        }
        $add("SELECT 'Z' AS {$m}");

        return implode("\n", $lines)."\n";
    }

    protected function parse(string $stdout, string $stderr, array $o): array
    {
        $marker = '__vdbm_'.$this->tag;
        $keep = $o['limit'] > 0 ? (int) $o['limit'] + 1 : PHP_INT_MAX;
        $results = [];
        $completed = false;
        $cur = null;
        $begin = null;
        $sets = [];
        foreach ($this->xmlSets($stdout, $keep) as $set) {
            if (($set['columns'][0] ?? null) === $marker && isset($set['rows'][0])) {
                $row = $set['rows'][0];
                if ($row[0] === 'B') {
                    $cur = (int) $row[1];
                    $begin = $row;
                    $sets = [];
                } elseif ($row[0] === 'E') {
                    $warnings = [];
                    if ($o['warnings'] && $sets !== []) {
                        foreach (array_pop($sets)['rows'] as $w) {
                            $warnings[] = ['level' => (string) ($w[0] ?? ''), 'code' => $w[1] ?? null, 'message' => (string) ($w[2] ?? '')];
                        }
                    }
                    $rc = $row[2] === null ? -1 : (int) $row[2];
                    $results[(int) $row[1]] = [
                        'sets' => $sets,
                        'affected' => $rc >= 0 ? $rc : null,
                        'insert_id' => $row[3] !== null && $row[3] !== '0' && $row[3] !== ($begin[2] ?? null) ? $row[3] : null,
                        'ms' => $begin !== null ? round(((float) $row[5] - (float) $begin[3]) * 1000, 2) : null,
                        'warning_count' => (int) $row[4],
                        'warnings' => $warnings,
                    ];
                    $cur = null;
                    $sets = [];
                } elseif ($row[0] === 'Z') {
                    $completed = true;
                }

                continue;
            }
            if ($cur !== null) {
                $sets[] = $set;
            }
        }

        $error = null;
        if (preg_match('/(?:^|\n)ERROR(?: (\d+))?(?: \(([0-9A-Za-z]{5})\))?(?: at line \d+)?: (.*)\z/s', $stderr, $m) === 1) {
            $error = [
                'i' => $cur ?? -1,
                'code' => $m[1] !== '' ? (int) $m[1] : null,
                'sqlstate' => $m[2] !== '' ? $m[2] : null,
                'message' => trim($m[3]),
            ];
        }

        return ['results' => $results, 'error' => $error, 'completed' => $completed && $error === null, 'current' => $cur];
    }

    /**
     * The result sets of the client's XML output, in order. Values arrive entity-encoded, so the tags found by
     * plain string search can't come from the data.
     *
     * @return \Generator<int, array{columns: list<string>, rows: list<list<?string>>, more: bool}>
     */
    private function xmlSets(string $x, int $keep): \Generator
    {
        $mark = $this->nulMark();
        $pos = 0;
        while (($start = strpos($x, '<resultset ', $pos)) !== false) {
            $open = strpos($x, '>', $start);
            $close = strpos($x, '</resultset>', $start);
            if ($open === false || $close === false) {
                return; // output cut short
            }
            $columns = null;
            $rows = [];
            $more = false;
            $p = $open + 1;
            while (($r = strpos($x, '<row>', $p)) !== false && $r < $close) {
                $end = strpos($x, '</row>', $r);
                if ($end === false || $end > $close) {
                    break;
                }
                $row = [];
                $names = [];
                $f = $r + 5;
                while (($f = strpos($x, '<field name="', $f)) !== false && $f < $end) {
                    $q = strpos($x, '"', $f + 13);
                    if ($columns === null) {
                        $names[] = self::decode(substr($x, $f + 13, $q - $f - 13));
                    }
                    if (substr_compare($x, ' xsi:nil="true" />', $q + 1, 18) === 0) {
                        $row[] = null;
                        $f = $q + 19;
                    } else {
                        $v = strpos($x, '</field>', $q);
                        $val = self::decode(substr($x, $q + 2, $v - $q - 2));
                        if (str_starts_with($val, $mark) && ctype_xdigit($hex = substr($val, strlen($mark)))) {
                            $val = (string) hex2bin($hex);
                        }
                        $row[] = $val;
                        $f = $v + 8;
                    }
                }
                $columns ??= $names;
                if (count($rows) < $keep) {
                    $rows[] = $row;
                } else {
                    $more = true;
                }
                $p = $end + 6;
            }
            yield ['columns' => $columns ?? [], 'rows' => $rows, 'more' => $more];
            $pos = $close + 12;
        }
    }

    /** Prefixes a text value that holds a NUL byte, which the client would print as a space; the rest is hex. */
    /** information_schema gives every view the comment "VIEW". */
    private function tableComment(array $t): string
    {
        $comment = (string) ($t['TABLE_COMMENT'] ?? '');

        return $comment === 'VIEW' && ($t['TABLE_TYPE'] ?? '') === 'VIEW' ? '' : $comment;
    }

    private function nulMark(): string
    {
        return 'vdbm0'.$this->tag.':';
    }

    /** A text expression whose value survives the client even when it holds a NUL byte (see nulMark()). */
    private function nulSafe(string $e): string
    {
        return "IF(INSTR({$e}, X'00') > 0, CONCAT('{$this->nulMark()}', HEX(CONVERT({$e} USING utf8mb4))), {$e})";
    }

    private static function decode(string $s): string
    {
        return str_contains($s, '&') ? strtr($s, ['&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'", '&amp;' => '&']) : $s;
    }

    protected function isStaleError(DbmException $e): bool
    {
        return (int) ($e->extra['code'] ?? 0) === 1054;
    }

    protected function guardStatements(array $statements): void
    {
        foreach ($statements as $k => $s) {
            if (stripos($s['sql'], 'NO_BACKSLASH_ESCAPES') !== false) {
                throw new DbmException(sprintf(
                    'Statement %d (line %d): this console always reads backslashes as escapes, so NO_BACKSLASH_ESCAPES cannot be turned on here.',
                    $k + 1,
                    $s['line']
                ), 'input', ['statement' => $k, 'line' => $s['line']]);
            }
        }
    }
}
