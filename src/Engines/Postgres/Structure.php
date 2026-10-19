<?php

namespace VitoDbManager\Engines\Postgres;

use VitoDbManager\Support\DbmException;

/**
 * The SQL for each structure change.
 *
 * Part of PostgresEngine, which is split by area (see there).
 */
trait Structure
{
    protected function ddlStatements(string $action, array $a): array
    {
        if ($action === 'create_table') {
            return [$this->createTableSql($a), true];
        }
        if ($action === 'create_schema') {
            return [['CREATE SCHEMA '.$this->quoteIdent($this->nameArg($a['name'] ?? null, 'schema name'))], false];
        }
        if ($action === 'drop_schema') {
            return [[
                'DROP SCHEMA '.$this->quoteIdent($this->nameArg($a['name'] ?? null, 'schema name')).(! empty($a['cascade']) ? ' CASCADE' : ''),
            ], false];
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
            'drop_column' => [[$alter.implode(', ', array_map(
                fn ($n) => 'DROP COLUMN '.$this->quoteIdent($n).$cascade,
                $this->columnList($meta, $a['columns'] ?? null),
            ))], false],
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
            $sql .= ' GENERATED ALWAYS AS ('.$this->exprArg($gen['expr'], 'The generated expression').')'
                .(($gen['stored'] ?? true) ? ' STORED' : ' VIRTUAL');
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
            $out[] = 'COMMENT ON COLUMN '.$ref.'.'.$col.' IS '
                .((string) $spec['comment'] === '' ? 'NULL' : $this->literal((string) $spec['comment']));
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
        $r = $this->query(
            ['SELECT conname FROM pg_constraint WHERE conindid = '.$this->regclass($index)." AND contype IN ('p', 'u', 'x')"],
            ['timeout' => 10],
        );
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
