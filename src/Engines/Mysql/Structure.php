<?php

namespace VitoDbManager\Engines\Mysql;

use VitoDbManager\Support\DbmException;

/**
 * The SQL for each structure change.
 *
 * Part of MysqlEngine, which is split by area (see there).
 */
trait Structure
{
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
            'drop_column' => [$alter.implode(', ', array_map(
                fn ($n) => 'DROP COLUMN '.$this->quoteIdent($n),
                $this->columnList($meta, $a['columns'] ?? null),
            ))],
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
            $sql .= ' GENERATED ALWAYS AS ('.$this->exprArg($gen['expr'], 'The generated expression').')'
                .(! empty($gen['stored']) ? ' STORED' : ' VIRTUAL');
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

        return $alter.$head
            .(($a['name'] ?? '') !== '' ? ' '.$this->quoteIdent($this->nameArg($a['name'], 'index name')) : '')
            .' ('.implode(', ', $parts).')';
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
