<?php

namespace VitoDbManager\Engines\Postgres;

use VitoDbManager\Support\DbmException;

/**
 * Reading the system catalogs: the overview, a table and its columns and keys, and the running processes.
 *
 * Part of PostgresEngine, which is split by area (see there).
 */
trait Metadata
{
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
            if ($notNull === 't' && ($key['mode'] === 'ctid' || $key['mode'] === 'none'
                || ($key['mode'] === 'unique' && count($cols) < count($key['cols'])))) {
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
            .' co.collname, CASE WHEN t.typtype = \'e\''
            .' THEN (SELECT json_agg(e.enumlabel ORDER BY e.enumsortorder) FROM pg_enum e WHERE e.enumtypid = t.oid) END,'
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
                ." pg_encoding_to_char(d.encoding), d.datcollate, current_setting('TimeZone')"
                .' FROM pg_database d WHERE d.datname = current_database()',
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
        $timing = static fn (string $def): array => preg_match('/\b(BEFORE|AFTER|INSTEAD OF) (.+?) ON /', $def, $m) === 1
            ? [$m[1], $m[2]]
            : [null, null];

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
        $cols = static fn (string $arr, string $rel): string => '(SELECT json_agg(a.attname ORDER BY k.n)'
            ." FROM unnest({$arr}) WITH ORDINALITY AS k(attnum, n)"
            ." JOIN pg_attribute a ON a.attrelid = {$rel} AND a.attnum = k.attnum)";
        $r = $this->query([
            'SELECT CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END, pg_total_relation_size(c.oid), pg_relation_size(c.oid),'
                .' pg_indexes_size(c.oid), obj_description(c.oid, \'pg_class\'), pg_get_userbyid(c.relowner), c.relrowsecurity,'
                .' (SELECT pi.inhparent::regclass::text FROM pg_inherits pi WHERE pi.inhrelid = c.oid AND c.relispartition LIMIT 1),'
                ." CASE WHEN c.relkind = 'p' THEN pg_get_partkeydef(c.oid) END,"
                .' CASE WHEN c.relispartition THEN pg_get_expr(c.relpartbound, c.oid) END,'
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
            'SELECT t.tgname, pg_get_triggerdef(t.oid, true), t.tgenabled FROM pg_trigger t'
                ." WHERE t.tgrelid = {$reg} AND NOT t.tgisinternal ORDER BY t.tgname",
            'SELECT c.oid::regclass::text, pg_get_expr(c.relpartbound, c.oid) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid'
                ." WHERE i.inhparent = {$reg} ORDER BY 1",
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
                'columns' => array_map(static fn ($c) => [
                    'name' => $c,
                    'expr' => null,
                    'length' => null,
                    'order' => str_ends_with((string) $c, ' DESC') ? 'desc' : 'asc',
                    'cardinality' => null,
                ], $json($x[6])),
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
                $fks[] = [
                    'name' => $x[0],
                    'schema' => $table['schema'],
                    'table' => null,
                    'columns' => $json($x[3]),
                    'ref_schema' => $x[4],
                    'ref_table' => $x[5],
                    'ref_columns' => $json($x[6]),
                    'on_update' => $rule($x[7]),
                    'on_delete' => $rule($x[8]),
                    'valid' => $x[9] === 't',
                ];
            } elseif ($x[1] === 'c') {
                $checks[] = [
                    'name' => $x[0],
                    'clause' => preg_replace('/^CHECK \((.*)\)( NOT VALID)?$/s', '$1', (string) $x[2]),
                    'valid' => $x[9] === 't',
                ];
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
            $sql = 'CREATE TABLE '.$ref." (\n".implode(",\n", $lines)."\n)"
                .($info['partition_key'] !== null ? ' PARTITION BY '.$info['partition_key'] : '').";\n";
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
}
