<?php

namespace VitoDbManager\Engines;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * MySQL (8.4, 9.x) and MariaDB (10.11 and later), through the server's own mysql / mariadb client.
 *
 * The client reads the script in batch mode and prints every result set as XML (--xml), which keeps NULL apart
 * from the text "NULL" and carries any value. An error goes to stderr and ends the script (there is no --force),
 * so the statement that failed is the one that began and never ended.
 */
final class MysqlEngine extends Engine
{
    private const LONG = ['tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'json'];

    private const GEOMETRY = ['geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection'];

    /** Turns NO_BACKSLASH_ESCAPES off: the client has to read backslashes in strings the way the splitter does. */
    private const KEEP_ESCAPES = "SET SESSION sql_mode = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', @@SESSION.sql_mode, ','), ',NO_BACKSLASH_ESCAPES,', ','))";

    public function dialect(): string
    {
        return Splitter::MYSQL;
    }

    private function maria(): bool
    {
        return $this->flavor === 'mariadb';
    }

    public function quoteIdent(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * Plain quoted text when it reads back character for character; otherwise hexadecimal, so a backslash,
     * a control character or invalid UTF-8 never depends on sql_mode or on how the client reads lines.
     */
    public function literal(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            return $this->binaryLiteral($value);
        }
        if (preg_match('/[\\\\\x00-\x08\x0b-\x1f\x7f]/', $value) !== 1) {
            return "'".str_replace("'", "''", $value)."'";
        }

        return "_utf8mb4 X'".bin2hex($value)."'";
    }

    public function binaryLiteral(string $raw): string
    {
        return "X'".bin2hex($raw)."'";
    }

    public function tableRef(array $table): string
    {
        return $this->quoteIdent($table['name']);
    }

    public function functions(): array
    {
        $common = ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP(3)', 'CURRENT_TIMESTAMP(6)', 'NOW()', 'CURRENT_DATE', 'CURRENT_TIME',
            'UTC_TIMESTAMP()', 'UTC_DATE()', 'UNIX_TIMESTAMP()', 'UUID()'];

        return $this->maria()
            ? [...$common, 'SYS_GUID()', 'UUID_v4()', 'UUID_v7()']
            : [...$common, 'UUID_TO_BIN(UUID())', 'UUID_TO_BIN(UUID(), 1)'];
    }

    // ── running scripts ────────────────────────────────────────────────────────────────────

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

    // ── metadata ───────────────────────────────────────────────────────────────────────────

    protected function loadMeta(array $table): array
    {
        $name = $this->literal($table['name']);
        $where = "TABLE_SCHEMA = DATABASE() AND TABLE_NAME = {$name}";
        $q = [
            "SELECT TABLE_TYPE, ENGINE, TABLE_ROWS FROM information_schema.TABLES WHERE {$where}",
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, CHARACTER_MAXIMUM_LENGTH,'
                .' COLUMN_COMMENT, COLLATION_NAME, GENERATION_EXPRESSION'.($this->maria() ? '' : ', SRS_ID')
                ." FROM information_schema.COLUMNS WHERE {$where} ORDER BY ORDINAL_POSITION",
            "SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE {$where} ORDER BY INDEX_NAME, SEQ_IN_INDEX",
        ];
        if ($this->maria()) {
            // MariaDB's JSON is LONGTEXT with a json_valid() check
            $q[] = "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = {$name}";
        }
        $r = $this->query($q, ['fresh_stats' => true, 'timeout' => 20]);
        $info = $this->assoc($r, 0)[0] ?? null;
        if ($info === null) {
            throw new DbmException("The table {$table['name']} does not exist.", 'not_found');
        }
        $json = [];
        foreach ($this->maria() ? $this->assoc($r, 3) : [] as $row) {
            if (preg_match_all('/json_valid\(`((?:[^`]|``)+)`\)/i', (string) $row['CHECK_CLAUSE'], $mm) > 0) {
                foreach ($mm[1] as $n) {
                    $json[str_replace('``', '`', $n)] = true;
                }
            }
        }
        $columns = [];
        foreach ($this->assoc($r, 1) as $row) {
            $columns[] = $this->column($row, isset($json[$row['COLUMN_NAME']]));
        }
        $indexes = [];
        foreach ($this->assoc($r, 2) as $row) {
            $indexes[$row['INDEX_NAME']]['unique'] = $row['NON_UNIQUE'] === '0';
            $indexes[$row['INDEX_NAME']]['cols'][] = $row['COLUMN_NAME']; // null for a functional part
        }
        $type = $this->tableType((string) $info['TABLE_TYPE']);

        return [
            'table' => [
                'schema' => null,
                'name' => $table['name'],
                'type' => $type,
                'engine' => $info['ENGINE'],
                'rows' => $info['TABLE_ROWS'] === null ? null : (int) $info['TABLE_ROWS'],
            ],
            'columns' => $columns,
            'key' => $this->chooseKey($indexes, $columns, $type),
        ];
    }

    /**
     * The key that names one row: the primary key, else the smallest unique key over NOT NULL columns
     * (a prefix index still makes the whole value unique), else a hash of the whole row for tables.
     *
     * @param  array<string, array{unique: bool, cols: list<?string>}>  $indexes
     * @param  list<array<string, mixed>>  $columns
     * @return array{mode: string, cols: list<string>, name: ?string}
     */
    private function chooseKey(array $indexes, array $columns, string $type): array
    {
        $by = $this->byName($columns);
        if (isset($indexes['PRIMARY']) && ! in_array(null, $indexes['PRIMARY']['cols'], true)) {
            return ['mode' => 'pk', 'cols' => $indexes['PRIMARY']['cols'], 'name' => 'PRIMARY'];
        }
        $best = null;
        foreach ($indexes as $name => $ix) {
            if (! $ix['unique'] || in_array(null, $ix['cols'], true)) {
                continue;
            }
            foreach ($ix['cols'] as $c) {
                if (! isset($by[$c]) || $by[$c]['nullable']) {
                    continue 2;
                }
            }
            if ($best === null || count($ix['cols']) < count($best[1])) {
                $best = [(string) $name, $ix['cols']];
            }
        }
        if ($best !== null) {
            return ['mode' => 'unique', 'cols' => $best[1], 'name' => $best[0]];
        }

        return ['mode' => $type === 'table' ? 'hash' : 'none', 'cols' => [], 'name' => null];
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, mixed>
     */
    private function column(array $row, bool $json): array
    {
        $dt = strtolower((string) $row['DATA_TYPE']);
        $ct = (string) $row['COLUMN_TYPE'];
        $extra = strtolower((string) $row['EXTRA']);
        $cat = $json ? 'json' : $this->category($dt, $ct);
        $nullable = $row['IS_NULLABLE'] === 'YES';
        $generated = preg_match('/\b(virtual|stored|persistent) generated\b/', $extra) === 1;
        $auto = str_contains($extra, 'auto_increment');
        $options = null;
        if (in_array($dt, ['enum', 'set'], true) && preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/", $ct, $mm) > 0) {
            $options = array_map(fn ($v) => $this->unquote($v), $mm[1]);
        }
        $expression = $generated ? (string) $row['GENERATION_EXPRESSION'] : null;
        if ($expression !== null && ! $this->maria()) {
            $expression = str_replace("\\'", "'", $expression); // MySQL shows the quotes escaped
        }

        return [
            'name' => (string) $row['COLUMN_NAME'],
            'type' => $ct,
            'udt' => $dt,
            'cat' => $cat,
            'nullable' => $nullable,
            'default' => $this->defaultOf($row['COLUMN_DEFAULT'], $nullable, $extra, $auto, $generated),
            'key' => strtolower((string) $row['COLUMN_KEY']),
            'generated' => $generated,
            'stored' => $generated && preg_match('/\b(stored|persistent) generated\b/', $extra) === 1,
            'expression' => $expression,
            'auto' => $auto,
            'identity' => null,
            'long' => in_array($dt, self::LONG, true) || in_array($cat, ['json', 'geo', 'vector'], true),
            'options' => $options,
            'max' => $row['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
            'comment' => (string) $row['COLUMN_COMMENT'],
            'collation' => $row['COLLATION_NAME'],
            'on_update' => preg_match('/on update (\S+)/', $extra, $m) === 1 ? strtoupper($m[1]) : null,
            'srid' => isset($row['SRS_ID']) ? (int) $row['SRS_ID'] : null,
        ];
    }

    private function category(string $dt, string $ct): string
    {
        return match (true) {
            str_starts_with(strtolower($ct), 'tinyint(1)') => 'bool',
            in_array($dt, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) => 'int',
            in_array($dt, ['decimal', 'numeric', 'float', 'double', 'real'], true) => 'num',
            $dt === 'bit' => 'bit',
            in_array($dt, ['char', 'varchar', 'uuid', 'inet4', 'inet6'], true) => 'str',
            in_array($dt, ['tinytext', 'text', 'mediumtext', 'longtext'], true) => 'text',
            $dt === 'json' => 'json',
            $dt === 'enum' => 'enum',
            $dt === 'set' => 'set',
            $dt === 'date' => 'date',
            in_array($dt, ['datetime', 'timestamp'], true) => 'datetime',
            $dt === 'time' => 'time',
            $dt === 'year' => 'year',
            in_array($dt, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true) => 'bin',
            in_array($dt, self::GEOMETRY, true) => 'geo',
            $dt === 'vector' => 'vector',
            default => 'other',
        };
    }

    /**
     * @return array{kind: string, value: ?string}
     */
    private function defaultOf(?string $d, bool $nullable, string $extra, bool $auto, bool $generated): array
    {
        if ($auto) {
            return ['kind' => 'auto', 'value' => null];
        }
        if ($generated) {
            return ['kind' => 'generated', 'value' => null];
        }
        if ($d === null) {
            return ['kind' => $nullable ? 'null' : 'none', 'value' => null];
        }
        if ($this->maria()) {
            // MariaDB quotes literal defaults and writes a NULL default as NULL
            if ($d === 'NULL') {
                return ['kind' => 'null', 'value' => null];
            }
            if (strlen($d) >= 2 && $d[0] === "'" && str_ends_with($d, "'")) {
                return ['kind' => 'literal', 'value' => $this->unquote(substr($d, 1, -1))];
            }

            return is_numeric($d) ? ['kind' => 'literal', 'value' => $d] : ['kind' => 'expr', 'value' => $d];
        }

        if (! str_contains($extra, 'default_generated')) {
            return ['kind' => 'literal', 'value' => $d];
        }
        // an expression default comes back with its quotes escaped; a TEXT or BLOB column keeps even a plain string
        // default as an expression, written _utf8mb4'text'
        $e = (string) preg_replace('/\\\\(.)/s', '$1', $d);
        if (preg_match("/^_[a-z0-9]+'(.*)'$/s", $e, $m) === 1) {
            return ['kind' => 'literal', 'value' => $this->unquote($m[1])];
        }

        return ['kind' => 'expr', 'value' => $e];
    }

    private function unquote(string $s): string
    {
        return (string) preg_replace_callback("/''|\\\\(.)/s", static fn ($m) => $m[0] === "''" ? "'" : match ($m[1]) {
            'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", default => $m[1],
        }, $s);
    }

    private function tableType(string $t): string
    {
        return match (strtoupper($t)) {
            'BASE TABLE', 'SYSTEM VERSIONED' => 'table',
            'VIEW' => 'view',
            'SEQUENCE' => 'sequence',
            'SYSTEM VIEW' => 'system view',
            default => strtolower($t),
        };
    }

    public function overview(): array
    {
        $r = $this->query([
            'SELECT DATABASE() AS db, VERSION() AS ver, @@version_comment AS ver_comment, @@character_set_database AS cs,'
                .' @@collation_database AS coll, @@session.time_zone AS tz, @@system_time_zone AS sys_tz',
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, AUTO_INCREMENT, TABLE_COLLATION,'
                .' TABLE_COMMENT, CREATE_TIME, UPDATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME',
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.TRIGGERS'
                .' WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME',
            'SELECT EVENT_NAME, STATUS, EVENT_TYPE, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS'
                .' WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME',
        ], ['fresh_stats' => true, 'timeout' => 30]);
        $db = $this->assoc($r, 0)[0] ?? [];
        $tables = [];
        $size = 0;
        foreach ($this->assoc($r, 1) as $t) {
            $data = $t['DATA_LENGTH'] === null ? null : (int) $t['DATA_LENGTH'];
            $index = $t['INDEX_LENGTH'] === null ? null : (int) $t['INDEX_LENGTH'];
            $size += ($data ?? 0) + ($index ?? 0);
            $tables[] = [
                'schema' => null,
                'name' => $t['TABLE_NAME'],
                'type' => $this->tableType((string) $t['TABLE_TYPE']),
                'engine' => $t['ENGINE'],
                'rows' => $t['TABLE_ROWS'] === null ? null : (int) $t['TABLE_ROWS'],
                'data' => $data,
                'index' => $index,
                'size' => $data === null ? null : $data + ($index ?? 0),
                'free' => $t['DATA_FREE'] === null ? null : (int) $t['DATA_FREE'],
                'auto_increment' => $t['AUTO_INCREMENT'] === null ? null : (int) $t['AUTO_INCREMENT'],
                'collation' => $t['TABLE_COLLATION'],
                'comment' => $this->tableComment($t),
                'created' => $t['CREATE_TIME'],
                'updated' => $t['UPDATE_TIME'],
                'partition_of' => null,
            ];
        }

        return [
            'flavor' => $this->flavor,
            'dialect' => $this->dialect(),
            'version' => $db['ver'] ?? null,
            'version_comment' => $db['ver_comment'] ?? null,
            'database' => [
                'name' => $db['db'] ?? $this->database,
                'charset' => $db['cs'] ?? null,
                'collation' => $db['coll'] ?? null,
                'size' => $size,
                'timezone' => ($db['tz'] ?? '') === 'SYSTEM' ? ($db['sys_tz'] ?? null) : ($db['tz'] ?? null),
            ],
            'schemas' => null,
            'tables' => $tables,
            'routines' => array_map(static fn ($x) => ['schema' => null, 'name' => $x['ROUTINE_NAME'], 'type' => strtolower((string) $x['ROUTINE_TYPE']), 'args' => null], $this->assoc($r, 2)),
            'triggers' => array_map(static fn ($x) => ['schema' => null, 'name' => $x['TRIGGER_NAME'], 'table' => $x['EVENT_OBJECT_TABLE'],
                'timing' => $x['ACTION_TIMING'], 'event' => $x['EVENT_MANIPULATION']], $this->assoc($r, 3)),
            'events' => array_map(static fn ($x) => ['name' => $x['EVENT_NAME'], 'status' => $x['STATUS'], 'type' => $x['EVENT_TYPE'],
                'every' => $x['INTERVAL_VALUE'] !== null ? $x['INTERVAL_VALUE'].' '.$x['INTERVAL_FIELD'] : null], $this->assoc($r, 4)),
            'extensions' => null,
            'functions' => $this->functions(),
        ];
    }

    public function structure(array $table): array
    {
        $meta = $this->meta($table, true);
        $name = $this->literal($table['name']);
        $ref = $this->tableRef($table);
        $where = "TABLE_SCHEMA = DATABASE() AND TABLE_NAME = {$name}";
        $fk = 'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,'
            .' r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            .' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME';
        $r = $this->query([
            'SELECT TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_ROWS, AVG_ROW_LENGTH, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, AUTO_INCREMENT, TABLE_COLLATION,'
                ." TABLE_COMMENT, CREATE_TIME, UPDATE_TIME FROM information_schema.TABLES WHERE {$where}",
            'SHOW INDEX FROM '.$ref,
            $fk." WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = {$name} AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            $fk." WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = {$name} ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            $this->maria()
                ? "SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = {$name} ORDER BY CONSTRAINT_NAME"
                : 'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc'
                    .' ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME'
                    ." WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = {$name} AND tc.CONSTRAINT_TYPE = 'CHECK' ORDER BY tc.CONSTRAINT_NAME",
            'SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS'
                ." WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = {$name} ORDER BY ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER",
            'SHOW CREATE TABLE '.$ref,
        ], ['fresh_stats' => true, 'timeout' => 30]);

        $t = $this->assoc($r, 0)[0] ?? [];
        $int = static fn ($v) => $v === null ? null : (int) $v;
        $indexes = [];
        foreach ($this->assoc($r, 1) as $row) {
            $n = (string) $row['Key_name'];
            $indexes[$n] ??= [
                'name' => $n,
                'primary' => $n === 'PRIMARY',
                'unique' => $row['Non_unique'] === '0',
                'type' => $row['Index_type'] ?? null,
                'columns' => [],
                'comment' => (string) ($row['Index_comment'] ?? ''),
                'visible' => ($row['Visible'] ?? 'YES') === 'YES' && ($row['Ignored'] ?? 'NO') !== 'YES',
                'constraint' => false,
                'definition' => null,
                'predicate' => null,
                'size' => null,
            ];
            $indexes[$n]['columns'][] = [
                'name' => $row['Column_name'],
                'expr' => $row['Expression'] ?? null,
                'length' => $int($row['Sub_part'] ?? null),
                'order' => ($row['Collation'] ?? 'A') === 'D' ? 'desc' : 'asc',
                'cardinality' => $int($row['Cardinality'] ?? null),
            ];
        }

        return [
            'table' => [
                'schema' => null,
                'name' => $table['name'],
                'type' => $meta['table']['type'],
                'engine' => $t['ENGINE'] ?? null,
                'row_format' => $t['ROW_FORMAT'] ?? null,
                'rows' => $int($t['TABLE_ROWS'] ?? null),
                'avg_row' => $int($t['AVG_ROW_LENGTH'] ?? null),
                'data' => $int($t['DATA_LENGTH'] ?? null),
                'index' => $int($t['INDEX_LENGTH'] ?? null),
                'size' => isset($t['DATA_LENGTH']) ? (int) $t['DATA_LENGTH'] + (int) ($t['INDEX_LENGTH'] ?? 0) : null,
                'free' => $int($t['DATA_FREE'] ?? null),
                'auto_increment' => $int($t['AUTO_INCREMENT'] ?? null),
                'collation' => $t['TABLE_COLLATION'] ?? null,
                'comment' => $this->tableComment($t),
                'created' => $t['CREATE_TIME'] ?? null,
                'updated' => $t['UPDATE_TIME'] ?? null,
            ],
            'columns' => $meta['columns'],
            'key' => $meta['key'],
            'indexes' => array_values($indexes),
            'foreign_keys' => $this->foreignKeys($this->assoc($r, 2), false),
            'references' => $this->foreignKeys($this->assoc($r, 3), true),
            'checks' => array_map(static fn ($c) => ['name' => $c['CONSTRAINT_NAME'], 'clause' => $c['CHECK_CLAUSE']], $this->assoc($r, 4)),
            'triggers' => array_map(static fn ($x) => ['name' => $x['TRIGGER_NAME'], 'timing' => $x['ACTION_TIMING'], 'event' => $x['EVENT_MANIPULATION'],
                'definition' => $x['ACTION_STATEMENT'], 'enabled' => true], $this->assoc($r, 5)),
            'partitions' => [],
            'ddl' => (string) (array_values($this->assoc($r, 6)[0] ?? [])[1] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, ?string>>  $rows
     * @return list<array<string, mixed>>
     */
    private function foreignKeys(array $rows, bool $incoming): array
    {
        $out = [];
        foreach ($rows as $row) {
            $k = $row['TABLE_NAME'].'.'.$row['CONSTRAINT_NAME'];
            $out[$k] ??= [
                'name' => $row['CONSTRAINT_NAME'],
                'schema' => null,
                'table' => $incoming ? $row['TABLE_NAME'] : null,
                'columns' => [],
                'ref_schema' => null,
                'ref_table' => $row['REFERENCED_TABLE_NAME'],
                'ref_columns' => [],
                'on_update' => $row['UPDATE_RULE'],
                'on_delete' => $row['DELETE_RULE'],
            ];
            $out[$k]['columns'][] = $row['COLUMN_NAME'];
            $out[$k]['ref_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        return array_values($out);
    }

    public function monitor(): array
    {
        $r = $this->query([
            'SELECT CONNECTION_ID()',
            'SHOW FULL PROCESSLIST',
            "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Threads_running', 'Questions', 'Slow_queries',"
                ." 'Max_used_connections', 'Aborted_connects', 'Bytes_received', 'Bytes_sent', 'Innodb_buffer_pool_read_requests',"
                ." 'Innodb_buffer_pool_reads', 'Com_commit', 'Com_rollback', 'Innodb_row_lock_waits')",
            "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections', 'innodb_buffer_pool_size')",
        ], ['timeout' => 10]);
        $self = (int) ($r[0]['sets'][0]['rows'][0][0] ?? 0);
        $processes = [];
        foreach ($this->assoc($r, 1) as $p) {
            $processes[] = [
                'id' => (int) $p['Id'],
                'user' => $p['User'],
                'host' => $p['Host'],
                'db' => $p['db'],
                'command' => $p['Command'],
                'time' => $p['Time'] === null ? null : (int) $p['Time'],
                'state' => $p['State'],
                'info' => $p['Info'] === null ? null : mb_strimwidth($p['Info'], 0, 4000, '…'),
                'app' => null,
                'self' => (int) $p['Id'] === $self,
            ];
        }
        $s = [];
        foreach ([...$this->assoc($r, 2), ...$this->assoc($r, 3)] as $row) {
            $s[strtolower((string) $row['Variable_name'])] = $row['Value'];
        }
        $n = static fn (string $k) => isset($s[$k]) ? (int) $s[$k] : null;
        $reads = (float) ($s['innodb_buffer_pool_read_requests'] ?? 0);

        return [
            'self' => $self,
            'processes' => $processes,
            'stats' => [
                'uptime' => $n('uptime'),
                'connections' => $n('threads_connected'),
                'max_connections' => $n('max_connections'),
                'max_used_connections' => $n('max_used_connections'),
                'running' => $n('threads_running'),
                'queries' => $n('questions'),
                'slow_queries' => $n('slow_queries'),
                'commits' => $n('com_commit'),
                'rollbacks' => $n('com_rollback'),
                'lock_waits' => $n('innodb_row_lock_waits'),
                'deadlocks' => null,
                'aborted_connects' => $n('aborted_connects'),
                'received' => $n('bytes_received'),
                'sent' => $n('bytes_sent'),
                'cache_hit' => $reads > 0 ? round(1 - (float) ($s['innodb_buffer_pool_reads'] ?? 0) / $reads, 4) : null,
                'buffer_pool' => $n('innodb_buffer_pool_size'),
            ],
        ];
    }

    public function kill(array $a): array
    {
        $id = (string) ($a['id'] ?? '');
        if (! ctype_digit($id) || (int) $id < 1) {
            throw new DbmException('Choose a connection.', 'input');
        }
        $sql = (! empty($a['connection']) ? 'KILL CONNECTION ' : 'KILL QUERY ').(int) $id;
        $this->query([$sql], ['timeout' => 10]);

        return ['killed' => (int) $id, 'sql' => $sql];
    }

    // ── cells, filters, values ─────────────────────────────────────────────────────────────

    protected function gridExpr(array $c, int $max): string
    {
        $q = $this->quoteIdent($c['name']);
        $cut = static fn (string $e): string => $max > 0 ? "SUBSTRING({$e}, 1, {$max})" : $e;

        return match ($c['cat']) {
            'bin' => 'HEX('.$cut($q).')',
            'bit' => "CAST({$q} AS UNSIGNED)",
            'geo' => $cut("ST_AsText({$q})"),
            'vector' => $cut(($this->maria() ? 'VEC_ToText(' : 'VECTOR_TO_STRING(').$q.')'),
            'str', 'text' => $this->nulSafe($c['long'] ? $cut($q) : $q),
            default => $c['long'] ? $cut($q) : $q,
        };
    }

    protected function lengthExpr(array $c): string
    {
        $q = $this->quoteIdent($c['name']);

        return match ($c['cat']) {
            'bin' => "LENGTH({$q})",
            'geo' => "CHAR_LENGTH(ST_AsText({$q}))",
            'vector' => 'CHAR_LENGTH('.($this->maria() ? 'VEC_ToText(' : 'VECTOR_TO_STRING(').$q.'))',
            default => "CHAR_LENGTH({$q})",
        };
    }

    protected function exportExpr(array $c): string
    {
        return $this->gridExpr($c, 0);
    }

    protected function rowKeyExpr(array $meta): ?string
    {
        return $meta['key']['mode'] === 'hash' ? $this->hashExpr($meta) : null;
    }

    protected function rowKeyMatch(array $meta, string $key): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {
            throw new DbmException('The table changed since the rows were loaded. Reload them.', 'stale');
        }

        return $this->hashExpr($meta)." = '{$key}'";
    }

    /** A fingerprint of every value of the row, for tables with no unique key. */
    private function hashExpr(array $meta): string
    {
        $parts = array_map(fn ($c) => 'IFNULL(HEX(CAST('.$this->quoteIdent($c['name'])." AS BINARY)), 'N')", $meta['columns']);

        // MySQL 9 dropped MD5 and SHA1
        return "LEFT(SHA2(CONCAT_WS('|', ".implode(', ', $parts).'), 256), 32)';
    }

    protected function searchCondition(array $c, string $term): ?string
    {
        if (in_array($c['cat'], ['bin', 'bit', 'geo', 'vector'], true)) {
            return null;
        }

        return $this->likeCondition($c, '%'.$this->likeEscape($term).'%', false);
    }

    protected function likeCondition(array $c, string $pattern, bool $not): string
    {
        return $this->quoteIdent($c['name']).($not ? ' NOT LIKE ' : ' LIKE ').$this->literal($pattern)." ESCAPE '!'";
    }

    protected function regexpCondition(array $c, string $pattern): string
    {
        return $this->quoteIdent($c['name']).' REGEXP '.$this->literal($pattern);
    }

    protected function typedLiteral(array $c, string $value): string
    {
        return match ($c['cat']) {
            'bit' => preg_match('/^\d{1,20}$/', $value) === 1 ? $value
                : (preg_match("/^b'[01]{1,64}'$/i", $value) === 1 ? $value
                : throw new DbmException("{$c['name']} takes a number, or bits written as b'0101'.", 'input')),
            'geo' => 'ST_GeomFromText('.$this->literal($value).(! empty($c['srid']) ? ', '.(int) $c['srid'] : '').')',
            'vector' => ($this->maria() ? 'VEC_FromText(' : 'STRING_TO_VECTOR(').$this->literal($value).')',
            default => $this->literal($value),
        };
    }

    protected function expression(string $expr): string
    {
        return $this->knownFunction($expr)
            ?? throw new DbmException('A cell can be set to one of these functions: '.implode(', ', $this->functions()).'.', 'input');
    }

    protected function insertDefaultsSql(array $table): string
    {
        return 'INSERT INTO '.$this->tableRef($table).' () VALUES ()';
    }

    protected function insertManySql(array $table, array $meta, array $names, array $tuples, string $mode): string
    {
        $sql = ($mode === 'ignore' ? 'INSERT IGNORE INTO ' : 'INSERT INTO ').$this->tableRef($table)
            .' ('.implode(', ', $names).') VALUES '.implode(', ', $tuples);
        if ($mode === 'upsert') {
            $key = in_array($meta['key']['mode'], ['pk', 'unique'], true) ? array_map(fn ($n) => $this->quoteIdent($n), $meta['key']['cols']) : [];
            $set = [];
            foreach ($names as $n) {
                if (! in_array($n, $key, true)) {
                    $set[] = $n.' = '.($this->maria() ? "VALUES({$n})" : "vdbm_new.{$n}");
                }
            }
            if ($set === []) {
                $set[] = $names[0].' = '.$names[0];
            }
            $sql .= ($this->maria() ? '' : ' AS vdbm_new').' ON DUPLICATE KEY UPDATE '.implode(', ', $set);
        }

        return $sql;
    }

    protected function limitOne(array $meta): string
    {
        // rows without a unique key may repeat; change one of them
        return $meta['key']['mode'] === 'hash' ? ' LIMIT 1' : '';
    }

    // ── structure changes ──────────────────────────────────────────────────────────────────

    protected function ddlStatements(string $action, array $a): array
    {
        if ($action === 'create_table') {
            return [[$this->createTableSql($a)], false];
        }
        $table = $this->tableArg($a);
        $ref = $this->tableRef($table);
        $meta = $this->meta($table, true);
        $alter = 'ALTER TABLE '.$ref.' ';
        $name = fn (string $what) => $this->quoteIdent($this->nameArg($a['name'] ?? null, $what));

        $sql = match ($action) {
            'rename_table' => ['RENAME TABLE '.$ref.' TO '.$this->quoteIdent($this->nameArg($a['to'] ?? null, 'table name'))],
            'duplicate_table' => $this->duplicateSql($ref, $meta, $a),
            'truncate_table' => ! empty($a['ignore_fk'])
                ? ['SET FOREIGN_KEY_CHECKS = 0', 'TRUNCATE TABLE '.$ref, 'SET FOREIGN_KEY_CHECKS = 1']
                : ['TRUNCATE TABLE '.$ref],
            'drop_table' => [match ($meta['table']['type']) {
                'view' => 'DROP VIEW ',
                'sequence' => 'DROP SEQUENCE ',
                default => 'DROP TABLE ',
            }.$ref],
            'add_column' => [$alter.'ADD COLUMN '.$this->columnSql($a['column'] ?? null).$this->position($meta, $a)],
            'modify_column' => [$alter.'CHANGE COLUMN '.$this->quoteIdent($this->columnList($meta, $a['name'] ?? null, 'column')[0])
                .' '.$this->columnSql($a['column'] ?? null).$this->position($meta, $a)],
            'drop_column' => [$alter.implode(', ', array_map(fn ($n) => 'DROP COLUMN '.$this->quoteIdent($n), $this->columnList($meta, $a['columns'] ?? null)))],
            'add_index' => [$this->addIndexSql($alter, $meta, $a)],
            'drop_index' => [$alter.(($a['name'] ?? '') === 'PRIMARY' ? 'DROP PRIMARY KEY' : 'DROP INDEX '.$name('index name'))],
            'add_fk' => [$this->addFkSql($alter, $meta, $a)],
            'drop_fk' => [$alter.'DROP FOREIGN KEY '.$name('constraint name')],
            'add_check' => [$alter.'ADD '.(($a['name'] ?? '') !== '' ? 'CONSTRAINT '.$name('constraint name').' ' : '')
                .'CHECK ('.$this->exprArg($a['clause'] ?? '', 'The check').')'],
            'drop_check' => [$alter.($this->maria() ? 'DROP CONSTRAINT ' : 'DROP CHECK ').$name('constraint name')],
            'table_options' => [$alter.$this->tableOptionsSql($a)],
            'maintenance' => [match ((string) ($a['task'] ?? '')) {
                'optimize' => 'OPTIMIZE TABLE ',
                'analyze' => 'ANALYZE TABLE ',
                'check' => 'CHECK TABLE ',
                'repair' => 'REPAIR TABLE ',
                'checksum' => 'CHECKSUM TABLE ',
                default => throw new DbmException('Unknown maintenance task.', 'input'),
            }.$ref],
            default => throw new DbmException('Unknown structure change.', 'input'),
        };

        return [$sql, false];
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function createTableSql(array $a): string
    {
        $t = $a['table'] ?? null;
        $name = $this->nameArg(is_array($t) ? ($t['name'] ?? null) : $t, 'table name');
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
        $sql = 'CREATE TABLE '.$this->quoteIdent($name)." (\n  ".implode(",\n  ", $defs)."\n)";
        if (($a['engine'] ?? '') !== '') {
            $sql .= ' ENGINE='.$this->word($a['engine'], 'engine');
        }
        if (($a['collation'] ?? '') !== '') {
            $sql .= ' COLLATE='.$this->word($a['collation'], 'collation');
        }
        if (($a['comment'] ?? '') !== '') {
            $sql .= ' COMMENT='.$this->literal((string) $a['comment']);
        }

        return $sql;
    }

    /**
     * A full column definition (MySQL's CHANGE COLUMN restates every attribute).
     */
    private function columnSql(mixed $spec): string
    {
        if (! is_array($spec)) {
            throw new DbmException('Describe the column.', 'input');
        }
        $type = $this->typeSql($spec);
        $sql = $this->quoteIdent($this->nameArg($spec['name'] ?? null, 'column name')).' '.$type;
        if (($spec['collation'] ?? '') !== '') {
            $sql .= ' COLLATE '.$this->word($spec['collation'], 'collation');
        }
        $gen = is_array($spec['generated'] ?? null) ? $spec['generated'] : null;
        if ($gen !== null && trim((string) ($gen['expr'] ?? '')) !== '') {
            $sql .= ' GENERATED ALWAYS AS ('.$this->exprArg($gen['expr'], 'The generated expression').')'.(! empty($gen['stored']) ? ' STORED' : ' VIRTUAL');
            if (! $this->maria()) {
                $sql .= empty($spec['nullable']) ? ' NOT NULL' : ' NULL';
            }
        } else {
            $sql .= empty($spec['nullable']) ? ' NOT NULL' : ' NULL';
            $d = $this->defaultArg($spec);
            $sql .= match ($d['kind']) {
                'null' => ' DEFAULT NULL',
                'literal' => ' DEFAULT '.$this->defaultLiteral($type, (string) $d['value']),
                'expr' => ' DEFAULT '.$this->defaultExpr((string) $d['value']),
                default => '',
            };
            if (! empty($spec['auto_increment'])) {
                $sql .= ' AUTO_INCREMENT';
            }
            if (! empty($spec['on_update'])) {
                $sql .= ' ON UPDATE '.$this->onUpdate($spec['on_update'], $type);
            }
        }
        if (($spec['comment'] ?? '') !== '') {
            $sql .= ' COMMENT '.$this->literal((string) $spec['comment']);
        }

        return $sql;
    }

    private function defaultLiteral(string $type, string $v): string
    {
        $base = strtolower((string) strtok($type, '( '));
        if ($base === 'bit' && preg_match('/^\d+$/', $v) === 1) {
            return $v;
        }
        // numbers stay numbers, so the preview reads like SHOW CREATE TABLE
        if (in_array($base, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'], true)
            && preg_match('/^-?\d+(\.\d+)?$/', $v) === 1) {
            return $v;
        }
        // TEXT, BLOB, JSON and spatial columns only take expression defaults
        if (in_array($base, [...self::LONG, ...self::GEOMETRY], true)) {
            return '('.$this->literal($v).')';
        }

        return $this->literal($v);
    }

    private function defaultExpr(string $v): string
    {
        $known = $this->knownFunction($v);
        if ($known !== null && preg_match('/^(CURRENT_TIMESTAMP|NOW)\b/i', $known) === 1) {
            return $known;
        }

        return '('.($known ?? $v).')';
    }

    private function onUpdate(mixed $v, string $type): string
    {
        if ($v === true || $v === '1' || $v === 1) {
            $fsp = preg_match('/^(?:datetime|timestamp)\s*\(\s*(\d)\s*\)/i', $type, $m) === 1 ? (int) $m[1] : 0;

            return 'CURRENT_TIMESTAMP'.($fsp > 0 ? "({$fsp})" : '');
        }
        $v = strtoupper(trim((string) $v));
        if (preg_match('/^(CURRENT_TIMESTAMP|NOW|LOCALTIMESTAMP|LOCALTIME)(\(\d?\))?$/', $v) !== 1) {
            throw new DbmException('ON UPDATE takes CURRENT_TIMESTAMP.', 'input');
        }

        return $v;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     */
    private function position(array $meta, array $a): string
    {
        if (! empty($a['first'])) {
            return ' FIRST';
        }
        if (($a['after'] ?? '') !== '') {
            return ' AFTER '.$this->quoteIdent($this->columnList($meta, $a['after'], 'column')[0]);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     * @return list<string>
     */
    private function duplicateSql(string $ref, array $meta, array $a): array
    {
        $to = $this->quoteIdent($this->nameArg($a['to'] ?? null, 'table name'));
        $out = ['CREATE TABLE '.$to.' LIKE '.$ref];
        if (! empty($a['data'])) {
            $cols = [];
            foreach ($meta['columns'] as $c) {
                if (! $c['generated']) {
                    $cols[] = $this->quoteIdent($c['name']);
                }
            }
            $list = implode(', ', $cols);
            $out[] = 'INSERT INTO '.$to.' ('.$list.') SELECT '.$list.' FROM '.$ref;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     */
    private function addIndexSql(string $alter, array $meta, array $a): string
    {
        $kind = strtolower((string) ($a['kind'] ?? 'index'));
        $parts = [];
        foreach ((array) ($a['columns'] ?? []) as $col) {
            $p = $this->quoteIdent($this->columnList($meta, is_array($col) ? ($col['name'] ?? null) : $col, 'column')[0]);
            if (is_array($col) && ! empty($col['length'])) {
                $p .= '('.$this->int($col['length'], 1, 3072).')';
            }
            if (is_array($col) && strtolower((string) ($col['order'] ?? '')) === 'desc') {
                $p .= ' DESC';
            }
            $parts[] = $p;
        }
        if ($parts === []) {
            throw new DbmException('Choose the columns of the index.', 'input');
        }
        if ($kind === 'primary') {
            return $alter.'ADD PRIMARY KEY ('.implode(', ', $parts).')';
        }
        $head = match ($kind) {
            'index' => 'ADD INDEX',
            'unique' => 'ADD UNIQUE INDEX',
            'fulltext' => 'ADD FULLTEXT INDEX',
            'spatial' => 'ADD SPATIAL INDEX',
            default => throw new DbmException("Unknown index kind {$kind}.", 'input'),
        };

        return $alter.$head.(($a['name'] ?? '') !== '' ? ' '.$this->quoteIdent($this->nameArg($a['name'], 'index name')) : '').' ('.implode(', ', $parts).')';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     */
    private function addFkSql(string $alter, array $meta, array $a): string
    {
        $cols = $this->columnList($meta, $a['columns'] ?? null);
        $rt = $a['ref_table'] ?? null;
        $refTable = ['schema' => null, 'name' => $this->nameArg(is_array($rt) ? ($rt['name'] ?? null) : $rt, 'referenced table')];
        $refCols = $this->columnList($this->meta($refTable, true), $a['ref_columns'] ?? null, 'referenced columns');
        if (count($cols) !== count($refCols)) {
            throw new DbmException('Choose as many referenced columns as columns.', 'input');
        }
        $q = fn (array $names) => implode(', ', array_map(fn ($n) => $this->quoteIdent($n), $names));
        $sql = $alter.'ADD '.(($a['name'] ?? '') !== '' ? 'CONSTRAINT '.$this->quoteIdent($this->nameArg($a['name'], 'constraint name')).' ' : '')
            .'FOREIGN KEY ('.$q($cols).') REFERENCES '.$this->tableRef($refTable).' ('.$q($refCols).')';
        if (($rule = $this->ruleSql($a['on_delete'] ?? '')) !== '') {
            $sql .= ' ON DELETE '.$rule;
        }
        if (($rule = $this->ruleSql($a['on_update'] ?? '')) !== '') {
            $sql .= ' ON UPDATE '.$rule;
        }

        return $sql;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function tableOptionsSql(array $a): string
    {
        $opts = [];
        if (array_key_exists('comment', $a)) {
            $opts[] = 'COMMENT = '.$this->literal((string) $a['comment']);
        }
        if (($a['engine'] ?? '') !== '') {
            $opts[] = 'ENGINE = '.$this->word($a['engine'], 'engine');
        }
        if (($a['auto_increment'] ?? '') !== '' && $a['auto_increment'] !== null) {
            $opts[] = 'AUTO_INCREMENT = '.$this->int($a['auto_increment'], 1, PHP_INT_MAX, 1);
        }
        if (($a['collation'] ?? '') !== '') {
            $coll = $this->word($a['collation'], 'collation');
            $opts[] = ! empty($a['convert'])
                ? 'CONVERT TO CHARACTER SET '.strtok($coll, '_').' COLLATE '.$coll
                : 'COLLATE = '.$coll;
        }
        if ($opts === []) {
            throw new DbmException('There is nothing to change.', 'input');
        }

        return implode(', ', $opts);
    }

    private function word(mixed $v, string $what): string
    {
        $v = trim((string) $v);
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $v) !== 1) {
            throw new DbmException("Invalid {$what}.", 'input');
        }

        return $v;
    }
}
