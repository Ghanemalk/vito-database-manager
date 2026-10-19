<?php

namespace VitoDbManager\Engines\Mysql;

use VitoDbManager\Support\DbmException;

/**
 * Reading information_schema: the overview, a table and its columns and keys, and the running processes.
 *
 * Part of MysqlEngine, which is split by area (see there).
 */
trait Metadata
{
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
            'routines' => array_map(static fn ($x) => [
                'schema' => null,
                'name' => $x['ROUTINE_NAME'],
                'type' => strtolower((string) $x['ROUTINE_TYPE']),
                'args' => null,
            ], $this->assoc($r, 2)),
            'triggers' => array_map(static fn ($x) => [
                'schema' => null,
                'name' => $x['TRIGGER_NAME'],
                'table' => $x['EVENT_OBJECT_TABLE'],
                'timing' => $x['ACTION_TIMING'],
                'event' => $x['EVENT_MANIPULATION'],
            ], $this->assoc($r, 3)),
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
            'SELECT TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_ROWS, AVG_ROW_LENGTH, DATA_LENGTH, INDEX_LENGTH, DATA_FREE,'
                ." AUTO_INCREMENT, TABLE_COLLATION, TABLE_COMMENT, CREATE_TIME, UPDATE_TIME FROM information_schema.TABLES WHERE {$where}",
            'SHOW INDEX FROM '.$ref,
            $fk." WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = {$name} AND k.REFERENCED_TABLE_NAME IS NOT NULL"
                .' ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            $fk." WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = {$name}"
                .' ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            $this->maria()
                ? 'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS'
                    ." WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = {$name} ORDER BY CONSTRAINT_NAME"
                : 'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc'
                    .' JOIN information_schema.CHECK_CONSTRAINTS cc'
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
            'triggers' => array_map(static fn ($x) => [
                'name' => $x['TRIGGER_NAME'],
                'timing' => $x['ACTION_TIMING'],
                'event' => $x['EVENT_MANIPULATION'],
                'definition' => $x['ACTION_STATEMENT'],
                'enabled' => true,
            ], $this->assoc($r, 5)),
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
}
