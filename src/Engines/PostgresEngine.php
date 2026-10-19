<?php

namespace VitoDbManager\Engines;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * PostgreSQL (15 to 18) through psql as the postgres superuser, the way Vito itself manages PostgreSQL.
 *
 * psql prints each result as CSV with a random NULL marker. The script echoes a marker line before and after
 * every statement and, after each one, checks :ERROR and stops at the first failure, echoing the SQLSTATE and
 * the message, so a failed statement never lets the ones after it run.
 */
final class PostgresEngine extends Engine
{
    /** @var array<int, array{0: int, 1: int}> script lines [first, last] of each statement, for placing notices */
    private array $lines = [];

    /** @var array<int, bool> statements wrapped in SELECT * FROM (…) LIMIT n */
    private array $wrapped = [];

    public function dialect(): string
    {
        return Splitter::PGSQL;
    }

    private function marker(): string
    {
        return '__VDBM_'.$this->tag.'__';
    }

    private function nullToken(): string
    {
        return 'vdbm_null_'.$this->tag;
    }

    public function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * A standard string when it holds no backslash or control character, else an E'' string with those escaped,
     * so the value reads back the same whatever standard_conforming_strings says.
     */
    public function literal(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new DbmException('This value is not valid UTF-8 text.', 'input');
        }
        if (str_contains($value, "\0")) {
            throw new DbmException('PostgreSQL text cannot contain NUL characters.', 'input');
        }
        if (preg_match('/[\\\\\x01-\x08\x0b-\x1f\x7f]/', $value) !== 1) {
            return "'".str_replace("'", "''", $value)."'";
        }

        return "E'".preg_replace_callback('/[\\\\\'\x01-\x08\x0b-\x1f\x7f]/', static fn ($m) => match ($m[0]) {
            '\\' => '\\\\',
            "'" => "''",
            "\r" => '\\r',
            default => sprintf('\\x%02X', ord($m[0])),
        }, $value)."'";
    }

    public function binaryLiteral(string $raw): string
    {
        return "decode('".bin2hex($raw)."', 'hex')";
    }

    public function tableRef(array $table): string
    {
        return $this->quoteIdent($table['schema'] ?? 'public').'.'.$this->quoteIdent($table['name']);
    }

    private function regclass(array $table): string
    {
        return 'to_regclass('.$this->literal($this->tableRef($table)).')';
    }

    public function functions(): array
    {
        return ['now()', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'LOCALTIMESTAMP', 'clock_timestamp()',
            'gen_random_uuid()', 'uuidv7()', 'CURRENT_USER'];
    }

    // ── running scripts ────────────────────────────────────────────────────────────────────

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
        if ($stderr === '' || preg_match_all('/^psql:[^\n]*?:(\d+): (ERROR|FATAL|PANIC|WARNING|NOTICE|INFO|LOG|DEBUG):  (.*?)(?=^psql:|\z)/ms', $stderr, $mm, PREG_SET_ORDER) === 0) {
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

    // ── metadata ───────────────────────────────────────────────────────────────────────────

    protected function loadMeta(array $table): array
    {
        $reg = $this->regclass($table);
        $r = $this->query([
            "SELECT c.relkind, CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END FROM pg_class c WHERE c.oid = {$reg}",
            $this->columnsQuery($reg),
            'SELECT i.indisprimary, i.indexprs IS NULL AND i.indpred IS NULL, ic.relname, i.indnkeyatts,'
                .' (SELECT json_agg(a.attname ORDER BY k.n) FROM unnest(i.indkey::int2[]) WITH ORDINALITY AS k(attnum, n)'
                .'  JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum WHERE k.n <= i.indnkeyatts),'
                .' (SELECT bool_and(a.attnotnull) FROM unnest(i.indkey::int2[]) WITH ORDINALITY AS k(attnum, n)'
                .'  JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum WHERE k.n <= i.indnkeyatts)'
                ." FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid WHERE i.indrelid = {$reg} AND i.indisunique AND i.indisvalid"
                .' ORDER BY i.indisprimary DESC, i.indnkeyatts',
        ], ['timeout' => 20]);
        $info = $r[0]['sets'][0]['rows'][0] ?? null;
        if ($info === null) {
            throw new DbmException("The table {$table['schema']}.{$table['name']} does not exist.", 'not_found');
        }
        $type = $this->relType((string) $info[0]);

        $key = ['mode' => in_array($type, ['table', 'partitioned'], true) ? 'ctid' : 'none', 'cols' => [], 'name' => null];
        $marks = [];
        foreach ($r[2]['sets'][0]['rows'] ?? [] as [$primary, $simple, $name, $nkey, $cols, $notNull]) {
            $cols = json_decode((string) $cols, true) ?: [];
            if ($simple !== 't' || count($cols) !== (int) $nkey) {
                continue;
            }
            if ($primary === 't') {
                $key = ['mode' => 'pk', 'cols' => $cols, 'name' => $name];
                foreach ($cols as $c) {
                    $marks[$c] = 'pri';
                }

                continue;
            }
            if (count($cols) === 1) {
                $marks[$cols[0]] ??= 'uni';
            }
            if ($notNull === 't' && ($key['mode'] === 'ctid' || $key['mode'] === 'none' || ($key['mode'] === 'unique' && count($cols) < count($key['cols'])))) {
                $key = ['mode' => 'unique', 'cols' => $cols, 'name' => $name];
            }
        }

        $columns = [];
        foreach ($r[1]['sets'][0]['rows'] ?? [] as $row) {
            $columns[] = $this->column($row, $marks[$row[0]] ?? '');
        }

        return [
            'table' => ['schema' => $table['schema'], 'name' => $table['name'], 'type' => $type, 'engine' => null,
                'rows' => $info[1] === null ? null : (int) $info[1]],
            'columns' => $columns,
            'key' => $key,
        ];
    }

    private function columnsQuery(string $reg): string
    {
        return 'SELECT a.attname, format_type(a.atttypid, a.atttypmod), COALESCE(bt.typname, t.typname), COALESCE(bt.typcategory, t.typcategory),'
            .' t.typtype, a.attnotnull, pg_get_expr(d.adbin, d.adrelid), a.attidentity, a.attgenerated, col_description(a.attrelid, a.attnum),'
            .' co.collname, CASE WHEN t.typtype = \'e\' THEN (SELECT json_agg(e.enumlabel ORDER BY e.enumsortorder) FROM pg_enum e WHERE e.enumtypid = t.oid) END,'
            .' a.atttypmod'
            .' FROM pg_attribute a JOIN pg_type t ON t.oid = a.atttypid'
            ." LEFT JOIN pg_type bt ON bt.oid = t.typbasetype AND t.typtype = 'd'"
            .' LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum'
            .' LEFT JOIN pg_collation co ON co.oid = a.attcollation AND a.attcollation <> t.typcollation'
            ." WHERE a.attrelid = {$reg} AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum";
    }

    /**
     * @param  list<?string>  $row  a row of columnsQuery()
     * @return array<string, mixed>
     */
    private function column(array $row, string $key): array
    {
        [$name, $type, $udt, $typcat, $typtype, $notNull, $def, $identity, $generated, $comment, $collation, $labels, $typmod] = $row;
        $udt = (string) $udt;
        $cat = $this->category($udt, (string) $typcat, (string) $typtype, (int) $typmod);
        $nullable = $notNull !== 't';
        $generated = (string) $generated;
        $identity = (string) $identity;

        return [
            'name' => (string) $name,
            'type' => (string) $type,
            'udt' => $udt,
            'cat' => $cat,
            'nullable' => $nullable,
            'default' => $this->defaultOf($def, $nullable, $identity, $generated),
            'default_sql' => $generated === '' ? $def : null,
            'key' => $key,
            'generated' => $generated !== '',
            'stored' => $generated === 's',
            'expression' => $generated !== '' ? $def : null,
            'auto' => $identity !== '' || ($def !== null && str_starts_with($def, 'nextval(')),
            'identity' => match ($identity) {
                'a' => 'always',
                'd' => 'by_default',
                default => null,
            },
            'long' => in_array($cat, ['text', 'json', 'array', 'bin', 'geo', 'vector'], true),
            'options' => $labels !== null ? (json_decode($labels, true) ?: []) : null,
            'max' => in_array($udt, ['varchar', 'bpchar'], true) && (int) $typmod > 4 ? (int) $typmod - 4 : null,
            'comment' => (string) $comment,
            'collation' => $collation,
            'on_update' => null,
            'srid' => null,
        ];
    }

    private function category(string $udt, string $typcat, string $typtype, int $typmod): string
    {
        return match (true) {
            $typtype === 'e' => 'enum',
            $typcat === 'B' => 'bool',
            in_array($udt, ['int2', 'int4', 'int8', 'oid', 'xid', 'cid'], true) => 'int',
            $typcat === 'N' => 'num',
            in_array($udt, ['text', 'xml', 'tsvector', 'tsquery', 'citext'], true), $udt === 'varchar' && $typmod < 0 => 'text',
            in_array($udt, ['varchar', 'bpchar', 'name', 'char', 'uuid'], true) => 'str',
            in_array($udt, ['json', 'jsonb'], true) => 'json',
            $udt === 'bytea' => 'bin',
            $udt === 'date' => 'date',
            in_array($udt, ['timestamp', 'timestamptz'], true) => 'datetime',
            in_array($udt, ['time', 'timetz'], true) => 'time',
            $typcat === 'A' => 'array',
            in_array($udt, ['geometry', 'geography'], true) => 'geo',
            $udt === 'vector' => 'vector',
            default => 'other',
        };
    }

    /**
     * @return array{kind: string, value: ?string}
     */
    private function defaultOf(?string $expr, bool $nullable, string $identity, string $generated): array
    {
        if ($identity !== '') {
            return ['kind' => 'auto', 'value' => null];
        }
        if ($generated !== '') {
            return ['kind' => 'generated', 'value' => null];
        }
        if ($expr === null) {
            return ['kind' => $nullable ? 'null' : 'none', 'value' => null];
        }
        if (str_starts_with($expr, 'nextval(')) {
            return ['kind' => 'auto', 'value' => $expr];
        }
        if (preg_match('/^NULL(::.*)?$/is', $expr) === 1) {
            return ['kind' => 'null', 'value' => null];
        }
        if (preg_match("/^'((?:[^']|'')*)'(?:::[\\w .\"\\[\\]()]+)?$/s", $expr, $m) === 1) {
            return ['kind' => 'literal', 'value' => str_replace("''", "'", $m[1])];
        }
        if (preg_match('/^\(?(-?\d+(?:\.\d+)?)\)?(?:::[\w ]+)?$/', $expr, $m) === 1) {
            return ['kind' => 'literal', 'value' => $m[1]];
        }
        if (in_array(strtolower($expr), ['true', 'false'], true)) {
            return ['kind' => 'literal', 'value' => strtolower($expr)];
        }

        return ['kind' => 'expr', 'value' => $expr];
    }

    private function relType(string $relkind): string
    {
        return match ($relkind) {
            'r' => 'table',
            'p' => 'partitioned',
            'v' => 'view',
            'm' => 'matview',
            'f' => 'foreign',
            default => $relkind,
        };
    }

    public function overview(): array
    {
        $user = "n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'";
        $r = $this->query([
            'SELECT current_database(), version(), current_setting(\'server_version\'), pg_database_size(current_database()),'
                ." pg_encoding_to_char(d.encoding), d.datcollate, current_setting('TimeZone') FROM pg_database d WHERE d.datname = current_database()",
            "SELECT n.nspname FROM pg_namespace n WHERE {$user} ORDER BY n.nspname = 'public' DESC, n.nspname",
            'SELECT n.nspname, c.relname, c.relkind, CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END,'
                .' pg_total_relation_size(c.oid), pg_relation_size(c.oid), pg_indexes_size(c.oid), obj_description(c.oid, \'pg_class\'),'
                .' (SELECT pi.inhparent::regclass::text FROM pg_inherits pi WHERE pi.inhrelid = c.oid AND c.relispartition LIMIT 1)'
                .' FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
                ." WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f') AND {$user} ORDER BY n.nspname, c.relname",
            'SELECT n.nspname, p.proname, p.prokind, pg_get_function_identity_arguments(p.oid) FROM pg_proc p'
                ." JOIN pg_namespace n ON n.oid = p.pronamespace WHERE {$user}"
                ." AND NOT EXISTS (SELECT 1 FROM pg_depend dp WHERE dp.classid = 'pg_proc'::regclass AND dp.objid = p.oid AND dp.deptype = 'e')"
                .' ORDER BY 1, 2 LIMIT 1000',
            'SELECT n.nspname, c.relname, t.tgname, pg_get_triggerdef(t.oid) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid'
                ." JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND {$user} ORDER BY 1, 2, 3",
            'SELECT extname, extversion FROM pg_extension ORDER BY 1',
        ], ['timeout' => 30]);
        $db = $r[0]['sets'][0]['rows'][0] ?? [];
        $int = static fn ($v) => $v === null ? null : (int) $v;
        $tables = [];
        foreach ($r[2]['sets'][0]['rows'] ?? [] as $t) {
            $type = $this->relType((string) $t[2]);
            $tables[] = [
                'schema' => $t[0],
                'name' => $t[1],
                'type' => $type,
                'engine' => null,
                'rows' => $int($t[3]),
                'data' => $int($t[5]),
                'index' => $int($t[6]),
                'size' => in_array($type, ['view'], true) ? null : $int($t[4]),
                'free' => null,
                'auto_increment' => null,
                'collation' => null,
                'comment' => (string) $t[7],
                'created' => null,
                'updated' => null,
                'partition_of' => $t[8],
            ];
        }
        $timing = static fn (string $def): array => preg_match('/\b(BEFORE|AFTER|INSTEAD OF) (.+?) ON /', $def, $m) === 1 ? [$m[1], $m[2]] : [null, null];

        return [
            'flavor' => $this->flavor,
            'dialect' => $this->dialect(),
            'version' => $db[2] ?? null,
            'version_comment' => $db[1] ?? null,
            'database' => [
                'name' => $db[0] ?? $this->database,
                'charset' => $db[4] ?? null,
                'collation' => $db[5] ?? null,
                'size' => $int($db[3] ?? null),
                'timezone' => $db[6] ?? null,
            ],
            'schemas' => array_map(static fn ($x) => $x[0], $r[1]['sets'][0]['rows'] ?? []),
            'tables' => $tables,
            'routines' => array_map(static fn ($x) => ['schema' => $x[0], 'name' => $x[1], 'type' => match ($x[2]) {
                'p' => 'procedure', 'a' => 'aggregate', 'w' => 'window', default => 'function',
            }, 'args' => $x[3]], $r[3]['sets'][0]['rows'] ?? []),
            'triggers' => array_map(static fn ($x) => ['schema' => $x[0], 'name' => $x[2], 'table' => $x[1],
                'timing' => $timing((string) $x[3])[0], 'event' => $timing((string) $x[3])[1]], $r[4]['sets'][0]['rows'] ?? []),
            'events' => null,
            'extensions' => array_map(static fn ($x) => ['name' => $x[0], 'version' => $x[1]], $r[5]['sets'][0]['rows'] ?? []),
            'functions' => $this->functions(),
        ];
    }

    public function structure(array $table): array
    {
        $meta = $this->meta($table, true);
        $reg = $this->regclass($table);
        $cols = static fn (string $arr, string $rel): string => "(SELECT json_agg(a.attname ORDER BY k.n) FROM unnest({$arr}) WITH ORDINALITY AS k(attnum, n)"
            ." JOIN pg_attribute a ON a.attrelid = {$rel} AND a.attnum = k.attnum)";
        $r = $this->query([
            'SELECT CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END, pg_total_relation_size(c.oid), pg_relation_size(c.oid),'
                .' pg_indexes_size(c.oid), obj_description(c.oid, \'pg_class\'), pg_get_userbyid(c.relowner), c.relrowsecurity,'
                .' (SELECT pi.inhparent::regclass::text FROM pg_inherits pi WHERE pi.inhrelid = c.oid AND c.relispartition LIMIT 1),'
                ." CASE WHEN c.relkind = 'p' THEN pg_get_partkeydef(c.oid) END, CASE WHEN c.relispartition THEN pg_get_expr(c.relpartbound, c.oid) END,"
                ." s.n_live_tup, s.n_dead_tup, s.last_vacuum, s.last_autovacuum, s.last_analyze, s.last_autoanalyze,"
                ." CASE WHEN c.relkind IN ('v', 'm') THEN pg_get_viewdef(c.oid, true) END"
                ." FROM pg_class c LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid WHERE c.oid = {$reg}",
            'SELECT ic.relname, i.indisprimary, i.indisunique, am.amname, pg_get_indexdef(i.indexrelid), pg_relation_size(i.indexrelid),'
                .' (SELECT json_agg(pg_get_indexdef(i.indexrelid, k, true) ORDER BY k) FROM generate_series(1, i.indnkeyatts) AS k),'
                .' pg_get_expr(i.indpred, i.indrelid), con.conname, i.indisvalid, obj_description(i.indexrelid, \'pg_class\')'
                .' FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid JOIN pg_am am ON am.oid = ic.relam'
                ." LEFT JOIN pg_constraint con ON con.conindid = i.indexrelid AND con.conrelid = i.indrelid AND con.contype IN ('p', 'u', 'x')"
                ." WHERE i.indrelid = {$reg} ORDER BY i.indisprimary DESC, ic.relname",
            'SELECT con.conname, con.contype, pg_get_constraintdef(con.oid, true), '.$cols('con.conkey', 'con.conrelid').', rn.nspname, rc.relname, '
                .$cols('con.confkey', 'con.confrelid').', con.confupdtype, con.confdeltype, con.convalidated'
                .' FROM pg_constraint con LEFT JOIN pg_class rc ON rc.oid = con.confrelid LEFT JOIN pg_namespace rn ON rn.oid = rc.relnamespace'
                ." WHERE con.conrelid = {$reg} ORDER BY con.contype, con.conname",
            'SELECT con.conname, sn.nspname, sc.relname, '.$cols('con.conkey', 'con.conrelid').', '.$cols('con.confkey', 'con.confrelid')
                .', con.confupdtype, con.confdeltype FROM pg_constraint con JOIN pg_class sc ON sc.oid = con.conrelid'
                ." JOIN pg_namespace sn ON sn.oid = sc.relnamespace WHERE con.confrelid = {$reg} AND con.contype = 'f' ORDER BY 2, 3, 1",
            "SELECT t.tgname, pg_get_triggerdef(t.oid, true), t.tgenabled FROM pg_trigger t WHERE t.tgrelid = {$reg} AND NOT t.tgisinternal ORDER BY t.tgname",
            "SELECT c.oid::regclass::text, pg_get_expr(c.relpartbound, c.oid) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = {$reg} ORDER BY 1",
        ], ['timeout' => 30]);

        $t = $r[0]['sets'][0]['rows'][0] ?? array_fill(0, 17, null);
        $int = static fn ($v) => $v === null ? null : (int) $v;
        $rule = static fn (?string $c): ?string => match ($c) {
            'a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT', default => null,
        };
        $json = static fn (?string $v): array => $v === null ? [] : (json_decode($v, true) ?: []);

        $indexes = [];
        foreach ($r[1]['sets'][0]['rows'] ?? [] as $x) {
            $indexes[] = [
                'name' => $x[0],
                'primary' => $x[1] === 't',
                'unique' => $x[2] === 't',
                'type' => $x[3],
                'columns' => array_map(static fn ($c) => ['name' => $c, 'expr' => null, 'length' => null, 'order' => str_ends_with((string) $c, ' DESC') ? 'desc' : 'asc', 'cardinality' => null], $json($x[6])),
                'comment' => (string) $x[10],
                'visible' => $x[9] === 't',
                'constraint' => $x[8],
                'definition' => $x[4],
                'predicate' => $x[7],
                'size' => $int($x[5]),
            ];
        }
        $fks = [];
        $checks = [];
        $constraints = [];
        foreach ($r[2]['sets'][0]['rows'] ?? [] as $x) {
            $constraints[] = ['name' => $x[0], 'type' => $x[1], 'definition' => $x[2]];
            if ($x[1] === 'f') {
                $fks[] = ['name' => $x[0], 'schema' => $table['schema'], 'table' => null, 'columns' => $json($x[3]), 'ref_schema' => $x[4],
                    'ref_table' => $x[5], 'ref_columns' => $json($x[6]), 'on_update' => $rule($x[7]), 'on_delete' => $rule($x[8]), 'valid' => $x[9] === 't'];
            } elseif ($x[1] === 'c') {
                $checks[] = ['name' => $x[0], 'clause' => preg_replace('/^CHECK \((.*)\)( NOT VALID)?$/s', '$1', (string) $x[2]), 'valid' => $x[9] === 't'];
            }
        }
        $triggers = array_map(static fn ($x) => [
            'name' => $x[0],
            'timing' => preg_match('/\b(BEFORE|AFTER|INSTEAD OF)\b/', (string) $x[1], $m) === 1 ? $m[1] : null,
            'event' => preg_match('/\b(?:BEFORE|AFTER|INSTEAD OF) (.+?) ON /', (string) $x[1], $m) === 1 ? $m[1] : null,
            'definition' => $x[1],
            'enabled' => $x[2] !== 'D',
        ], $r[4]['sets'][0]['rows'] ?? []);
        $info = [
            'schema' => $table['schema'],
            'name' => $table['name'],
            'type' => $meta['table']['type'],
            'engine' => null,
            'rows' => $int($t[0]),
            'live_rows' => $int($t[10]),
            'dead_rows' => $int($t[11]),
            'size' => $int($t[1]),
            'data' => $int($t[2]),
            'index' => $int($t[3]),
            'comment' => (string) $t[4],
            'owner' => $t[5],
            'rls' => $t[6] === 't',
            'partition_of' => $t[7],
            'partition_key' => $t[8],
            'partition_bound' => $t[9],
            'last_vacuum' => $t[12] ?? $t[13],
            'last_analyze' => $t[14] ?? $t[15],
            'view_definition' => $t[16],
        ];

        return [
            'table' => $info,
            'columns' => $meta['columns'],
            'key' => $meta['key'],
            'indexes' => $indexes,
            'foreign_keys' => $fks,
            'references' => array_map(static fn ($x) => ['name' => $x[0], 'schema' => $x[1], 'table' => $x[2], 'columns' => $json($x[3]),
                'ref_schema' => $table['schema'], 'ref_table' => $table['name'], 'ref_columns' => $json($x[4]),
                'on_update' => $rule($x[5]), 'on_delete' => $rule($x[6])], $r[3]['sets'][0]['rows'] ?? []),
            'checks' => $checks,
            'triggers' => $triggers,
            'partitions' => array_map(static fn ($x) => ['name' => $x[0], 'bound' => $x[1]], $r[5]['sets'][0]['rows'] ?? []),
            'ddl' => $this->ddlText($table, $meta, $info, $indexes, $constraints, $triggers),
        ];
    }

    /**
     * PostgreSQL has no SHOW CREATE TABLE: an equivalent built from the catalog.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $info
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<array<string, mixed>>  $constraints
     * @param  list<array<string, mixed>>  $triggers
     */
    private function ddlText(array $table, array $meta, array $info, array $indexes, array $constraints, array $triggers): string
    {
        $ref = $this->tableRef($table);
        if (in_array($meta['table']['type'], ['view', 'matview'], true)) {
            return ($meta['table']['type'] === 'matview' ? 'CREATE MATERIALIZED VIEW ' : 'CREATE OR REPLACE VIEW ').$ref." AS\n"
                .rtrim((string) $info['view_definition'], "; \n").";\n";
        }
        if ($info['partition_of'] !== null) {
            $sql = 'CREATE TABLE '.$ref.' PARTITION OF '.$info['partition_of'].' '.$info['partition_bound'].";\n";
        } else {
            $lines = [];
            foreach ($meta['columns'] as $c) {
                $l = '  '.$this->quoteIdent($c['name']).' '.$c['type'];
                if ($c['collation'] !== null) {
                    $l .= ' COLLATE '.$this->quoteIdent($c['collation']);
                }
                if ($c['generated']) {
                    $l .= ' GENERATED ALWAYS AS ('.$c['expression'].')'.($c['stored'] ? ' STORED' : ' VIRTUAL');
                } elseif ($c['identity'] !== null) {
                    $l .= ' GENERATED '.($c['identity'] === 'always' ? 'ALWAYS' : 'BY DEFAULT').' AS IDENTITY';
                } elseif ($c['default_sql'] !== null) {
                    $l .= ' DEFAULT '.$c['default_sql'];
                }
                if (! $c['nullable']) {
                    $l .= ' NOT NULL';
                }
                $lines[] = $l;
            }
            foreach ($constraints as $con) {
                if ($con['type'] !== 'n') {
                    $lines[] = '  CONSTRAINT '.$this->quoteIdent($con['name']).' '.$con['definition'];
                }
            }
            $sql = 'CREATE TABLE '.$ref." (\n".implode(",\n", $lines)."\n)".($info['partition_key'] !== null ? ' PARTITION BY '.$info['partition_key'] : '').";\n";
        }
        foreach ($indexes as $ix) {
            if ($ix['constraint'] === null) {
                $sql .= $ix['definition'].";\n";
            }
        }
        if ($info['comment'] !== '') {
            $sql .= 'COMMENT ON TABLE '.$ref.' IS '.$this->literal($info['comment']).";\n";
        }
        foreach ($meta['columns'] as $c) {
            if ($c['comment'] !== '') {
                $sql .= 'COMMENT ON COLUMN '.$ref.'.'.$this->quoteIdent($c['name']).' IS '.$this->literal($c['comment']).";\n";
            }
        }
        foreach ($triggers as $tg) {
            $sql .= $tg['definition'].";\n";
        }

        return $sql;
    }

    public function monitor(): array
    {
        $r = $this->query([
            'SELECT pg_backend_pid()',
            'SELECT pid, usename, datname, application_name, client_addr::text, state, wait_event_type, wait_event,'
                .' EXTRACT(EPOCH FROM now() - query_start)::bigint, left(query, 4000)'
                ." FROM pg_stat_activity WHERE backend_type = 'client backend' ORDER BY query_start NULLS LAST",
            'SELECT numbackends, xact_commit, xact_rollback, blks_read, blks_hit, tup_inserted, tup_updated, tup_deleted, deadlocks, temp_bytes,'
                .' EXTRACT(EPOCH FROM now() - pg_postmaster_start_time())::bigint, current_setting(\'max_connections\')::int,'
                ." (SELECT count(*) FROM pg_stat_activity WHERE state = 'active' AND backend_type = 'client backend'),"
                ." (SELECT count(*) FROM pg_stat_activity WHERE backend_type = 'client backend'), pg_size_bytes(current_setting('shared_buffers'))"
                .' FROM pg_stat_database WHERE datname = current_database()',
        ], ['timeout' => 10]);
        $self = (int) ($r[0]['sets'][0]['rows'][0][0] ?? 0);
        $processes = [];
        foreach ($r[1]['sets'][0]['rows'] ?? [] as $p) {
            $processes[] = [
                'id' => (int) $p[0],
                'user' => $p[1],
                'host' => $p[4],
                'db' => $p[2],
                'command' => $p[5],
                'time' => $p[8] === null ? null : (int) $p[8],
                'state' => $p[6] !== null ? $p[6].': '.$p[7] : null,
                'info' => $p[9],
                'app' => $p[3],
                'self' => (int) $p[0] === $self,
            ];
        }
        $s = $r[2]['sets'][0]['rows'][0] ?? array_fill(0, 15, null);
        $n = static fn ($v) => $v === null ? null : (int) $v;
        $blocks = (float) $s[3] + (float) $s[4];

        return [
            'self' => $self,
            'processes' => $processes,
            'stats' => [
                'uptime' => $n($s[10]),
                'connections' => $n($s[13]),
                'max_connections' => $n($s[11]),
                'max_used_connections' => null,
                'running' => $n($s[12]),
                'queries' => null,
                'slow_queries' => null,
                'commits' => $n($s[1]),
                'rollbacks' => $n($s[2]),
                'lock_waits' => null,
                'deadlocks' => $n($s[8]),
                'aborted_connects' => null,
                'received' => null,
                'sent' => null,
                'cache_hit' => $blocks > 0 ? round((float) $s[4] / $blocks, 4) : null,
                'buffer_pool' => $n($s[14]),
                'inserted' => $n($s[5]),
                'updated' => $n($s[6]),
                'deleted' => $n($s[7]),
                'temp_bytes' => $n($s[9]),
            ],
        ];
    }

    public function kill(array $a): array
    {
        $id = (string) ($a['id'] ?? '');
        if (! ctype_digit($id) || (int) $id < 1) {
            throw new DbmException('Choose a connection.', 'input');
        }
        $sql = 'SELECT '.(! empty($a['connection']) ? 'pg_terminate_backend' : 'pg_cancel_backend').'('.(int) $id.')';
        $r = $this->query([$sql], ['timeout' => 10]);

        return ['killed' => ($r[0]['sets'][0]['rows'][0][0] ?? 'f') === 't' ? (int) $id : null, 'sql' => $sql];
    }

    // ── cells, filters, values ─────────────────────────────────────────────────────────────

    protected function gridExpr(array $c, int $max): string
    {
        $q = $this->quoteIdent($c['name']);
        if ($c['cat'] === 'bin') {
            return $max > 0 ? "encode(substring({$q} from 1 for {$max}), 'hex')" : "encode({$q}, 'hex')";
        }
        $text = $c['cat'] === 'geo' && in_array($c['udt'], ['geometry', 'geography'], true) ? "ST_AsEWKT({$q})" : "{$q}::text";
        if ($c['long'] && $max > 0) {
            return "left({$text}, {$max})";
        }

        return $text === "{$q}::text" ? $q : $text;
    }

    protected function lengthExpr(array $c): string
    {
        $q = $this->quoteIdent($c['name']);
        if ($c['cat'] === 'bin') {
            return "octet_length({$q})";
        }

        return $c['cat'] === 'geo' && in_array($c['udt'], ['geometry', 'geography'], true) ? "char_length(ST_AsEWKT({$q}))" : "char_length({$q}::text)";
    }

    protected function exportExpr(array $c): string
    {
        return $this->gridExpr($c, 0);
    }

    protected function rowKeyExpr(array $meta): ?string
    {
        return $meta['key']['mode'] === 'ctid' ? "tableoid::bigint::text || ':' || ctid::text" : null;
    }

    protected function rowKeyMatch(array $meta, string $key): string
    {
        if (preg_match('/^(\d+):\((\d+),(\d+)\)$/', $key, $m) !== 1) {
            throw new DbmException('The table changed since the rows were loaded. Reload them.', 'stale');
        }

        return "tableoid = {$m[1]} AND ctid = '({$m[2]},{$m[3]})'::tid";
    }

    protected function searchCondition(array $c, string $term): ?string
    {
        if ($c['cat'] === 'bin') {
            return null;
        }

        return $this->likeCondition($c, '%'.$this->likeEscape($term).'%', false);
    }

    protected function likeCondition(array $c, string $pattern, bool $not): string
    {
        return $this->quoteIdent($c['name']).'::text'.($not ? ' NOT ILIKE ' : ' ILIKE ').$this->literal($pattern)." ESCAPE '!'";
    }

    protected function regexpCondition(array $c, string $pattern): string
    {
        return $this->quoteIdent($c['name']).'::text ~* '.$this->literal($pattern);
    }

    protected function typedLiteral(array $c, string $value): string
    {
        // an untyped literal takes the column's type
        return $c['cat'] === 'bin' ? "convert_to({$this->literal($value)}, 'UTF8')" : $this->literal($value);
    }

    protected function expression(string $expr): string
    {
        return $this->knownFunction($expr)
            ?? throw new DbmException('A cell can be set to one of these functions: '.implode(', ', $this->functions()).'.', 'input');
    }

    protected function overriding(array $meta, array $columns): string
    {
        foreach ($meta['columns'] as $c) {
            if ($c['identity'] === 'always' && in_array($c['name'], $columns, true)) {
                return ' OVERRIDING SYSTEM VALUE';
            }
        }

        return '';
    }

    protected function insertDefaultsSql(array $table): string
    {
        return 'INSERT INTO '.$this->tableRef($table).' DEFAULT VALUES';
    }

    protected function insertManySql(array $table, array $meta, array $names, array $tuples, string $mode): string
    {
        $raw = [];
        foreach ($meta['columns'] as $c) {
            if (in_array($this->quoteIdent($c['name']), $names, true)) {
                $raw[] = $c['name'];
            }
        }
        $sql = 'INSERT INTO '.$this->tableRef($table).' ('.implode(', ', $names).')'.$this->overriding($meta, $raw)
            .' VALUES '.implode(', ', $tuples);
        if ($mode === 'ignore') {
            return $sql.' ON CONFLICT DO NOTHING';
        }
        if ($mode === 'upsert') {
            if (! in_array($meta['key']['mode'], ['pk', 'unique'], true)) {
                throw new DbmException('Updating existing rows needs a primary key or a unique key.', 'input');
            }
            $key = array_map(fn ($n) => $this->quoteIdent($n), $meta['key']['cols']);
            $set = [];
            foreach ($names as $n) {
                if (! in_array($n, $key, true)) {
                    $set[] = "{$n} = EXCLUDED.{$n}";
                }
            }
            $sql .= ' ON CONFLICT ('.implode(', ', $key).') '.($set === [] ? 'DO NOTHING' : 'DO UPDATE SET '.implode(', ', $set));
        }

        return $sql;
    }

    protected function limitOne(array $meta): string
    {
        return '';
    }

    // ── structure changes ──────────────────────────────────────────────────────────────────

    protected function ddlStatements(string $action, array $a): array
    {
        if ($action === 'create_table') {
            return [$this->createTableSql($a), true];
        }
        if ($action === 'create_schema') {
            return [['CREATE SCHEMA '.$this->quoteIdent($this->nameArg($a['name'] ?? null, 'schema name'))], false];
        }
        if ($action === 'drop_schema') {
            return [['DROP SCHEMA '.$this->quoteIdent($this->nameArg($a['name'] ?? null, 'schema name')).(! empty($a['cascade']) ? ' CASCADE' : '')], false];
        }
        $table = $this->tableArg($a);
        $ref = $this->tableRef($table);
        $meta = $this->meta($table, true);
        $alter = 'ALTER TABLE '.$ref.' ';
        $kind = match ($meta['table']['type']) {
            'view' => 'VIEW',
            'matview' => 'MATERIALIZED VIEW',
            'foreign' => 'FOREIGN TABLE',
            default => 'TABLE',
        };
        $cascade = ! empty($a['cascade']) ? ' CASCADE' : '';
        $name = fn (string $what) => $this->quoteIdent($this->nameArg($a['name'] ?? null, $what));

        return match ($action) {
            'rename_table' => [['ALTER '.$kind.' '.$ref.' RENAME TO '.$this->quoteIdent($this->nameArg($a['to'] ?? null, 'table name'))], false],
            'move_table' => [['ALTER '.$kind.' '.$ref.' SET SCHEMA '.$this->quoteIdent($this->nameArg($a['to'] ?? null, 'schema name'))], false],
            'duplicate_table' => [$this->duplicateSql($table, $meta, $a), true],
            'truncate_table' => [['TRUNCATE TABLE '.$ref.(! empty($a['restart_identity']) ? ' RESTART IDENTITY' : '').$cascade], false],
            'drop_table' => [['DROP '.$kind.' '.$ref.$cascade], false],
            'add_column' => [$this->addColumnSql($ref, $a['column'] ?? null), true],
            'modify_column' => [$this->modifyColumnSql($ref, $meta, $a), true],
            'drop_column' => [[$alter.implode(', ', array_map(fn ($n) => 'DROP COLUMN '.$this->quoteIdent($n).$cascade, $this->columnList($meta, $a['columns'] ?? null)))], false],
            'add_index' => [[$this->addIndexSql($table, $meta, $a)], false],
            'drop_index' => [[$this->dropIndexSql($table, $a)], false],
            'add_fk' => [[$this->addFkSql($table, $meta, $a)], false],
            'drop_fk', 'drop_check', 'drop_constraint' => [[$alter.'DROP CONSTRAINT '.$name('constraint name').$cascade], false],
            'add_check' => [[$alter.'ADD '.(($a['name'] ?? '') !== '' ? 'CONSTRAINT '.$name('constraint name').' ' : '')
                .'CHECK ('.$this->exprArg($a['clause'] ?? '', 'The check').')'], false],
            'table_options' => [array_key_exists('comment', $a)
                ? ['COMMENT ON '.$kind.' '.$ref.' IS '.((string) $a['comment'] === '' ? 'NULL' : $this->literal((string) $a['comment']))]
                : throw new DbmException('There is nothing to change.', 'input'), false],
            'maintenance' => [[match ((string) ($a['task'] ?? '')) {
                'vacuum' => 'VACUUM (ANALYZE) ',
                'vacuum_full' => 'VACUUM (FULL, ANALYZE) ',
                'analyze' => 'ANALYZE ',
                'reindex' => 'REINDEX TABLE ',
                'refresh' => 'REFRESH MATERIALIZED VIEW ',
                default => throw new DbmException('Unknown maintenance task.', 'input'),
            }.$ref], false],
            default => throw new DbmException('Unknown structure change.', 'input'),
        };
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<string>
     */
    private function createTableSql(array $a): array
    {
        $t = $a['table'] ?? null;
        $table = [
            'schema' => $this->nameArg(is_array($t) ? ($t['schema'] ?? 'public') : 'public', 'schema name'),
            'name' => $this->nameArg(is_array($t) ? ($t['name'] ?? null) : $t, 'table name'),
        ];
        $ref = $this->tableRef($table);
        $specs = $this->specsArg($a);
        $defs = array_map(fn ($s) => $this->columnSql($s), $specs);
        $names = array_map(static fn ($s) => (string) $s['name'], $specs);
        $pk = array_values(array_map('strval', (array) ($a['primary'] ?? [])));
        foreach ($pk as $p) {
            if (! in_array($p, $names, true)) {
                throw new DbmException("The primary key column {$p} is not in the table.", 'input');
            }
        }
        if ($pk !== []) {
            $defs[] = 'PRIMARY KEY ('.implode(', ', array_map(fn ($p) => $this->quoteIdent($p), $pk)).')';
        }
        $out = ['CREATE TABLE '.$ref." (\n  ".implode(",\n  ", $defs)."\n)"];
        if (($a['comment'] ?? '') !== '') {
            $out[] = 'COMMENT ON TABLE '.$ref.' IS '.$this->literal((string) $a['comment']);
        }
        foreach ($specs as $s) {
            if (($s['comment'] ?? '') !== '') {
                $out[] = 'COMMENT ON COLUMN '.$ref.'.'.$this->quoteIdent((string) $s['name']).' IS '.$this->literal((string) $s['comment']);
            }
        }

        return $out;
    }

    private function columnSql(mixed $spec): string
    {
        if (! is_array($spec)) {
            throw new DbmException('Describe the column.', 'input');
        }
        $sql = $this->quoteIdent($this->nameArg($spec['name'] ?? null, 'column name')).' '.$this->typeSql($spec);
        if (($spec['collation'] ?? '') !== '') {
            $sql .= ' COLLATE '.$this->quoteIdent($this->nameArg($spec['collation'], 'collation'));
        }
        $gen = is_array($spec['generated'] ?? null) ? $spec['generated'] : null;
        if ($gen !== null && trim((string) ($gen['expr'] ?? '')) !== '') {
            $sql .= ' GENERATED ALWAYS AS ('.$this->exprArg($gen['expr'], 'The generated expression').')'.(($gen['stored'] ?? true) ? ' STORED' : ' VIRTUAL');
        } elseif (in_array($spec['identity'] ?? null, ['always', 'by_default'], true)) {
            $sql .= ' GENERATED '.($spec['identity'] === 'always' ? 'ALWAYS' : 'BY DEFAULT').' AS IDENTITY';
        } else {
            $sql .= $this->defaultClause($spec);
        }
        if (empty($spec['nullable'])) {
            $sql .= ' NOT NULL';
        }

        return $sql;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function defaultClause(array $spec): string
    {
        $d = $this->defaultArg($spec);

        return match ($d['kind']) {
            'null' => ' DEFAULT NULL',
            'literal' => ' DEFAULT '.$this->literal((string) $d['value']),
            'expr' => ' DEFAULT '.($this->knownFunction((string) $d['value']) ?? $d['value']),
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    private function addColumnSql(string $ref, mixed $spec): array
    {
        $out = ['ALTER TABLE '.$ref.' ADD COLUMN '.$this->columnSql($spec)];
        if (is_array($spec) && ($spec['comment'] ?? '') !== '') {
            $out[] = 'COMMENT ON COLUMN '.$ref.'.'.$this->quoteIdent((string) $spec['name']).' IS '.$this->literal((string) $spec['comment']);
        }

        return $out;
    }

    /**
     * One ALTER per attribute that differs, in an order that keeps each step valid.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     * @return list<string>
     */
    private function modifyColumnSql(string $ref, array $meta, array $a): array
    {
        $old = $this->columnList($meta, $a['name'] ?? null, 'column')[0];
        $c = $this->byName($meta['columns'])[$old];
        $spec = is_array($a['column'] ?? null) ? $a['column'] : throw new DbmException('Describe the column.', 'input');
        $alter = 'ALTER TABLE '.$ref.' ';
        $new = $this->nameArg($spec['name'] ?? $old, 'column name');
        $col = $this->quoteIdent($new);
        $out = [];
        if ($new !== $old) {
            $out[] = $alter.'RENAME COLUMN '.$this->quoteIdent($old).' TO '.$col;
        }
        $type = $this->typeSql($spec + ['type' => $c['type']]);
        $coll = (string) ($spec['collation'] ?? '');
        if (strtolower($type) !== strtolower($c['type']) || ($coll !== '' && $coll !== $c['collation'])) {
            $out[] = $alter.'ALTER COLUMN '.$col.' TYPE '.$type.($coll !== '' ? ' COLLATE '.$this->quoteIdent($coll) : '')
                .(($spec['using'] ?? '') !== '' ? ' USING '.$this->exprArg($spec['using'], 'USING') : '');
        }
        $identity = in_array($spec['identity'] ?? null, ['always', 'by_default'], true) ? $spec['identity'] : null;
        if ($c['identity'] !== null && $identity === null) {
            $out[] = $alter.'ALTER COLUMN '.$col.' DROP IDENTITY';
        }
        $gen = is_array($spec['generated'] ?? null) ? trim((string) ($spec['generated']['expr'] ?? '')) : '';
        if ($c['generated'] && $gen === '') {
            $out[] = $alter.'ALTER COLUMN '.$col.' DROP EXPRESSION';
        } elseif ($c['generated'] && $gen !== $c['expression']) {
            $out[] = $alter.'ALTER COLUMN '.$col.' SET EXPRESSION AS ('.$this->exprArg($gen, 'The generated expression').')';
        }
        if ($identity === null && ! $c['generated'] && array_key_exists('default', $spec)) {
            $clause = $this->defaultClause($spec);
            $now = $this->defaultArg($spec);
            $same = $now['kind'] === $c['default']['kind'] && $now['value'] === $c['default']['value'];
            if (! $same) {
                $out[] = $alter.'ALTER COLUMN '.$col.($clause === '' ? ' DROP DEFAULT' : ' SET'.$clause);
            }
        }
        $nullable = ! empty($spec['nullable']);
        if ($nullable !== $c['nullable']) {
            $out[] = $alter.'ALTER COLUMN '.$col.($nullable ? ' DROP NOT NULL' : ' SET NOT NULL');
        }
        if ($identity !== null && $c['identity'] === null) {
            $out[] = $alter.'ALTER COLUMN '.$col.' ADD GENERATED '.($identity === 'always' ? 'ALWAYS' : 'BY DEFAULT').' AS IDENTITY';
        } elseif ($identity !== null && $identity !== $c['identity']) {
            $out[] = $alter.'ALTER COLUMN '.$col.' SET GENERATED '.($identity === 'always' ? 'ALWAYS' : 'BY DEFAULT');
        }
        if (array_key_exists('comment', $spec) && (string) $spec['comment'] !== $c['comment']) {
            $out[] = 'COMMENT ON COLUMN '.$ref.'.'.$col.' IS '.((string) $spec['comment'] === '' ? 'NULL' : $this->literal((string) $spec['comment']));
        }
        if ($out === []) {
            throw new DbmException('Nothing changed.', 'input');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     * @return list<string>
     */
    private function duplicateSql(array $table, array $meta, array $a): array
    {
        $to = $this->tableRef(['schema' => $table['schema'], 'name' => $this->nameArg($a['to'] ?? null, 'table name')]);
        $out = ['CREATE TABLE '.$to.' (LIKE '.$this->tableRef($table).' INCLUDING ALL)'];
        if (! empty($a['data'])) {
            $cols = [];
            $always = false;
            foreach ($meta['columns'] as $c) {
                if (! $c['generated']) {
                    $cols[] = $this->quoteIdent($c['name']);
                    $always = $always || $c['identity'] === 'always';
                }
            }
            $list = implode(', ', $cols);
            $out[] = 'INSERT INTO '.$to.' ('.$list.')'.($always ? ' OVERRIDING SYSTEM VALUE' : '').' SELECT '.$list.' FROM '.$this->tableRef($table);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     */
    private function addIndexSql(array $table, array $meta, array $a): string
    {
        $kind = strtolower((string) ($a['kind'] ?? 'index'));
        $parts = [];
        foreach ((array) ($a['columns'] ?? []) as $col) {
            $p = $this->quoteIdent($this->columnList($meta, is_array($col) ? ($col['name'] ?? null) : $col, 'column')[0]);
            if (is_array($col) && strtolower((string) ($col['order'] ?? '')) === 'desc') {
                $p .= ' DESC';
            }
            $parts[] = $p;
        }
        if ($parts === []) {
            throw new DbmException('Choose the columns of the index.', 'input');
        }
        if ($kind === 'primary') {
            return 'ALTER TABLE '.$this->tableRef($table).' ADD PRIMARY KEY ('.implode(', ', $parts).')';
        }
        if (! in_array($kind, ['index', 'unique'], true)) {
            throw new DbmException("Unknown index kind {$kind}.", 'input');
        }
        $method = strtolower((string) ($a['method'] ?? 'btree'));
        if (! in_array($method, ['btree', 'hash', 'gist', 'spgist', 'gin', 'brin'], true)) {
            throw new DbmException("Unknown index method {$method}.", 'input');
        }

        return 'CREATE '.($kind === 'unique' ? 'UNIQUE ' : '').'INDEX '.(! empty($a['concurrently']) ? 'CONCURRENTLY ' : '')
            .(($a['name'] ?? '') !== '' ? $this->quoteIdent($this->nameArg($a['name'], 'index name')).' ' : '')
            .'ON '.$this->tableRef($table).($method !== 'btree' ? ' USING '.$method : '').' ('.implode(', ', $parts).')'
            .(($a['where'] ?? '') !== '' ? ' WHERE '.$this->exprArg($a['where'], 'The index condition') : '');
    }

    /**
     * An index that backs a primary key or unique constraint goes with its constraint.
     *
     * @param  array<string, mixed>  $a
     */
    private function dropIndexSql(array $table, array $a): string
    {
        $index = ['schema' => $table['schema'], 'name' => $this->nameArg($a['name'] ?? null, 'index name')];
        $r = $this->query(['SELECT conname FROM pg_constraint WHERE conindid = '.$this->regclass($index)." AND contype IN ('p', 'u', 'x')"], ['timeout' => 10]);
        $constraint = $r[0]['sets'][0]['rows'][0][0] ?? null;
        if ($constraint !== null) {
            return 'ALTER TABLE '.$this->tableRef($table).' DROP CONSTRAINT '.$this->quoteIdent($constraint);
        }

        return 'DROP INDEX '.(! empty($a['concurrently']) ? 'CONCURRENTLY ' : '').$this->tableRef($index);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     */
    private function addFkSql(array $table, array $meta, array $a): string
    {
        $cols = $this->columnList($meta, $a['columns'] ?? null);
        $rt = $a['ref_table'] ?? null;
        $refTable = [
            'schema' => $this->nameArg(is_array($rt) ? ($rt['schema'] ?? $table['schema']) : $table['schema'], 'schema name'),
            'name' => $this->nameArg(is_array($rt) ? ($rt['name'] ?? null) : $rt, 'referenced table'),
        ];
        $refCols = $this->columnList($this->meta($refTable, true), $a['ref_columns'] ?? null, 'referenced columns');
        if (count($cols) !== count($refCols)) {
            throw new DbmException('Choose as many referenced columns as columns.', 'input');
        }
        $q = fn (array $names) => implode(', ', array_map(fn ($n) => $this->quoteIdent($n), $names));
        $sql = 'ALTER TABLE '.$this->tableRef($table).' ADD '
            .(($a['name'] ?? '') !== '' ? 'CONSTRAINT '.$this->quoteIdent($this->nameArg($a['name'], 'constraint name')).' ' : '')
            .'FOREIGN KEY ('.$q($cols).') REFERENCES '.$this->tableRef($refTable).' ('.$q($refCols).')';
        if (($rule = $this->ruleSql($a['on_delete'] ?? '')) !== '') {
            $sql .= ' ON DELETE '.$rule;
        }
        if (($rule = $this->ruleSql($a['on_update'] ?? '')) !== '') {
            $sql .= ' ON UPDATE '.$rule;
        }

        return $sql;
    }
}
