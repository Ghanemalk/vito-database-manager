<?php

namespace VitoDbManager\Engines\Concerns;

use VitoDbManager\Support\DbmException;

/**
 * Inserting, updating and deleting rows, one or many, with the values written as literals.
 *
 * Part of Engine, which is split by area (see there).
 */
trait Writes
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function assertEditable(array $meta): void
    {
        $type = $meta['table']['type'] ?? 'table';
        if (! in_array($type, ['table', 'partitioned'], true)) {
            throw new DbmException("Rows of a {$type} cannot be edited here.", 'input');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<mixed>  $values
     * @return array{0: list<string>, 1: list<string>}
     */
    protected function assignments(array $meta, array $values): array
    {
        $by = $this->byName($meta['columns']);
        $names = [];
        $exprs = [];
        foreach ($values as $name => $v) {
            $c = $by[(string) $name] ?? throw new DbmException("Unknown column {$name}.", 'input');
            if ($c['generated']) {
                continue;
            }
            $names[] = $this->quoteIdent($c['name']);
            $exprs[] = $this->valueSql($c, $v);
        }

        return [$names, $exprs];
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function valueSql(array $c, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            $v = $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        if (is_array($v)) {
            if (! empty($v['default'])) {
                return 'DEFAULT';
            }
            if (isset($v['expr'])) {
                return $this->expression((string) $v['expr']);
            }
            if (isset($v['x'])) {
                return $this->binaryLiteral($this->hex((string) $v['x']));
            }
            if (isset($v['b'])) {
                $raw = base64_decode((string) $v['b'], true);
                if ($raw === false) {
                    throw new DbmException("Invalid file data for {$c['name']}.", 'input');
                }

                return $this->binaryLiteral($raw);
            }
            throw new DbmException("Invalid value for {$c['name']}.", 'input');
        }
        if (! is_string($v)) {
            throw new DbmException("Invalid value for {$c['name']}.", 'input');
        }

        return $this->typedLiteral($c, $v);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function insert(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        $values = (array) ($a['values'] ?? []);
        [$names, $exprs] = $this->assignments($meta, $values);
        $sql = $names === []
            ? $this->insertDefaultsSql($table)
            : 'INSERT INTO '.$this->tableRef($table).' ('.implode(', ', $names).')'
                .$this->overriding($meta, array_map('strval', array_keys($values))).' VALUES ('.implode(', ', $exprs).')';
        $r = $this->query([$sql], ['warnings' => true, 'tx' => true]);

        return ['affected' => $r[0]['affected'], 'insert_id' => $r[0]['insert_id'], 'warnings' => $r[0]['warnings'], 'sql' => $sql];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function update(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        [$names, $exprs] = $this->assignments($meta, (array) ($a['values'] ?? []));
        if ($names === []) {
            return ['affected' => 0, 'warnings' => [], 'sql' => null];
        }
        $where = $this->keyWhere($meta, $a['key'] ?? null);
        $set = [];
        foreach ($names as $k => $n) {
            $set[] = $n.' = '.$exprs[$k];
        }
        $sql = 'UPDATE '.$this->tableRef($table).' SET '.implode(', ', $set).' WHERE '.$where.$this->limitOne($meta);
        $r = $this->query([$sql, 'SELECT COUNT(*) FROM '.$this->tableRef($table).' WHERE '.$where], ['warnings' => true, 'tx' => true]);
        $affected = (int) $r[0]['affected'];
        $exists = (int) ($r[1]['sets'][0]['rows'][0][0] ?? 0);
        if ($affected === 0 && $exists === 0 && ! $this->keyChanged($meta, $a)) {
            throw new DbmException('This row no longer exists. It may have been changed or deleted; reload the rows.', 'stale');
        }

        return ['affected' => $affected, 'warnings' => $r[0]['warnings'], 'sql' => $sql];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     */
    private function keyChanged(array $meta, array $a): bool
    {
        $values = (array) ($a['values'] ?? []);
        foreach ($meta['key']['cols'] ?? [] as $name) {
            if (array_key_exists($name, $values)) {
                return true;
            }
        }

        return in_array($meta['key']['mode'], ['hash', 'ctid'], true);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function delete(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        $keys = array_values((array) ($a['keys'] ?? []));
        if ($keys === []) {
            throw new DbmException('Select rows to delete.', 'input');
        }
        if (count($keys) > 1000) {
            throw new DbmException('Delete at most 1000 rows at a time.', 'input');
        }
        $statements = [];
        foreach ($keys as $key) {
            $statements[] = 'DELETE FROM '.$this->tableRef($table).' WHERE '.$this->keyWhere($meta, $key).$this->limitOne($meta);
        }
        $r = $this->query($statements, ['tx' => true]);
        $deleted = 0;
        foreach ($r as $res) {
            $deleted += (int) ($res['affected'] ?? 0);
        }

        return ['deleted' => $deleted, 'requested' => count($keys), 'sql' => $statements[0].(count($statements) > 1 ? ' …' : '')];
    }

    /**
     * Inserts many rows (CSV import). mode: insert | ignore (skip rows that clash with a unique key) | upsert (update them).
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function insertMany(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        $by = $this->byName($meta['columns']);
        $columns = array_values(array_map('strval', (array) ($a['columns'] ?? [])));
        if ($columns === []) {
            throw new DbmException('Choose at least one column.', 'input');
        }
        $cols = [];
        foreach ($columns as $name) {
            $c = $by[$name] ?? throw new DbmException("Unknown column {$name}.", 'input');
            if ($c['generated']) {
                throw new DbmException("{$name} is a generated column and cannot be imported.", 'input');
            }
            $cols[] = $c;
        }
        $rows = array_values((array) ($a['rows'] ?? []));
        if ($rows === [] || count($rows) > 2000) {
            throw new DbmException('Send between 1 and 2000 rows at a time.', 'input');
        }
        $tuples = [];
        foreach ($rows as $n => $row) {
            $row = array_values((array) $row);
            if (count($row) !== count($cols)) {
                throw new DbmException(sprintf('Row %d has %d values; %d expected.', $n + 1, count($row), count($cols)), 'input');
            }
            $exprs = [];
            foreach ($cols as $k => $c) {
                $exprs[] = $this->valueSql($c, $row[$k]);
            }
            $tuples[] = '('.implode(', ', $exprs).')';
        }
        $mode = in_array($a['mode'] ?? 'insert', ['insert', 'ignore', 'upsert'], true) ? (string) ($a['mode'] ?? 'insert') : 'insert';
        $sql = $this->insertManySql($table, $meta, array_map(fn ($c) => $this->quoteIdent($c['name']), $cols), $tuples, $mode);
        $r = $this->query([$sql], ['tx' => true, 'warnings' => true]);

        return ['affected' => $r[0]['affected'], 'rows' => count($rows), 'warnings' => array_slice($r[0]['warnings'], 0, 20)];
    }
}
