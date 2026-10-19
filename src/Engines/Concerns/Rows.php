<?php

namespace VitoDbManager\Engines\Concerns;

use VitoDbManager\Support\DbmException;

/**
 * Reading rows for the grid and the row editor, how each value goes to the browser, and how a row is found again by its key.
 *
 * Part of Engine, which is split by area (see there).
 */
trait Rows
{
    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function rows(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        try {
            return $this->fetchRows($table, $meta, $a);
        } catch (DbmException $e) {
            if (! $this->isStaleError($e)) {
                throw $e;
            }

            return $this->fetchRows($table, $this->meta($table, true), $a);
        }
    }

    /**
     * @param  array{schema: ?string, name: string}  $table
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function fetchRows(array $table, array $meta, array $a): array
    {
        $cols = $meta['columns'];
        $by = $this->byName($cols);
        $cellMax = $this->int($a['cell_max'] ?? 300, 40, 20000, 300);
        $per = $this->int($a['per'] ?? 25, 1, 500, 25);
        $page = $this->int($a['page'] ?? 1, 1, 10_000_000, 1);
        $where = $this->whereSql($a, $by);
        $order = $this->orderSql($a['sort'] ?? [], $by, $meta['key']);
        $keyCols = in_array($meta['key']['mode'], ['pk', 'unique'], true) ? $meta['key']['cols'] : [];

        $select = [];
        foreach ($cols as $c) {
            $select[] = $this->gridExpr($c, in_array($c['name'], $keyCols, true) ? 0 : $cellMax);
        }
        $lenAt = [];
        foreach ($cols as $i => $c) {
            if ($c['long'] && ! in_array($c['name'], $keyCols, true)) {
                $lenAt[$i] = count($select);
                $select[] = $this->lengthExpr($c);
            }
        }
        $keyAt = null;
        if (($keyExpr = $this->rowKeyExpr($meta)) !== null) {
            $keyAt = count($select);
            $select[] = $keyExpr;
        }

        $from = $this->tableRef($table);
        $statements = [[
            'sql' => 'SELECT '.implode(', ', $select).' FROM '.$from.$where.$order.' LIMIT '.$per.' OFFSET '.(($page - 1) * $per),
            'timeout' => 30,
        ]];
        $estimate = $meta['table']['rows'] ?? null;
        $countIt = $where !== '' || $estimate === null || $estimate < 2_000_000;
        if ($countIt) {
            $statements[] = ['sql' => 'SELECT COUNT(*) FROM '.$from.$where, 'timeout' => 10];
        }

        $r = $this->execute($statements);
        if (! isset($r['results'][0])) {
            throw $this->sqlException($r['error'] ?? ['message' => 'The rows could not be read.']);
        }

        $index = [];
        foreach ($cols as $i => $c) {
            $index[$c['name']] = $i;
        }
        $rows = [];
        $keys = [];
        foreach ($r['results'][0]['sets'][0]['rows'] ?? [] as $raw) {
            $cells = [];
            foreach ($cols as $i => $c) {
                $full = isset($lenAt[$i]) && isset($raw[$lenAt[$i]]) ? (int) $raw[$lenAt[$i]] : null;
                $cells[] = $this->gridCell($c, $raw[$i] ?? null, $full, $cellMax);
            }
            $rows[] = $cells;
            $keys[] = $this->rowKey($meta, $raw, $index, $keyAt);
        }

        $total = $estimate;
        $approx = true;
        $countError = null;
        if (isset($r['results'][1])) {
            $total = (int) ($r['results'][1]['sets'][0]['rows'][0][0] ?? 0);
            $approx = false;
        } elseif ($countIt && $r['error'] !== null) {
            $countError = $r['error']['message'];
        }

        return [
            'table' => $table,
            'columns' => $cols,
            'key' => $meta['key'],
            'type' => $meta['table']['type'] ?? 'table',
            'rows' => $rows,
            'keys' => $keys,
            'total' => $total,
            'approx' => $approx,
            'count_error' => $countError,
            'page' => $page,
            'per' => $per,
        ];
    }

    /**
     * The full values of one row, for the row editor.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function row(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $cols = $meta['columns'];
        $max = 4_000_000;
        $select = [];
        foreach ($cols as $c) {
            $select[] = $this->gridExpr($c, $c['long'] ? $max : 0);
        }
        $lenAt = [];
        foreach ($cols as $i => $c) {
            if ($c['long']) {
                $lenAt[$i] = count($select);
                $select[] = $this->lengthExpr($c);
            }
        }
        $sql = 'SELECT '.implode(', ', $select).' FROM '.$this->tableRef($table).' WHERE '.$this->keyWhere($meta, $a['key'] ?? null).' LIMIT 2';
        $r = $this->query([$sql], ['output' => 128 * 1024 * 1024]);
        $found = $r[0]['sets'][0]['rows'] ?? [];
        if ($found === []) {
            throw new DbmException('This row no longer exists. It may have been changed or deleted; reload the rows.', 'stale');
        }
        $raw = $found[0];
        $cells = [];
        foreach ($cols as $i => $c) {
            $full = isset($lenAt[$i]) && isset($raw[$lenAt[$i]]) ? (int) $raw[$lenAt[$i]] : null;
            $cells[] = $this->gridCell($c, $raw[$i] ?? null, $full, $max);
        }

        return ['table' => $table, 'columns' => $cols, 'key' => $meta['key'], 'row' => $cells, 'ambiguous' => count($found) > 1];
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function gridCell(array $c, ?string $v, ?int $full, int $max): mixed
    {
        if ($v === null) {
            return null;
        }
        if ($c['cat'] === 'bin') {
            $hex = preg_replace('/^0x/i', '', $v) ?? '';
            $raw = ctype_xdigit($hex) && strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : $v;

            return ['b' => base64_encode($raw), 'n' => $full ?? strlen($raw)];
        }
        if (preg_match('//u', $v) !== 1) {
            return ['b' => base64_encode($v), 'n' => strlen($v)];
        }
        if ($full !== null && $max > 0 && $full > $max) {
            return ['t' => $v, 'n' => $full];
        }

        return $v;
    }

    /**
     * A cell of an arbitrary query result.
     */
    protected function genericCell(?string $v, int $max): mixed
    {
        if ($v === null) {
            return null;
        }
        if (preg_match('//u', $v) !== 1) {
            return ['b' => base64_encode(substr($v, 0, $max)), 'n' => strlen($v)];
        }
        if (strlen($v) > $max) {
            $cut = substr($v, 0, $max);
            while ($cut !== '' && preg_match('//u', $cut) !== 1) {
                $cut = substr($cut, 0, -1);
            }

            return ['t' => $cut, 'n' => strlen($v), 'u' => 'bytes'];
        }

        return $v;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<?string>  $raw
     * @param  array<string, int>  $index
     * @return list<mixed>|null
     */
    protected function rowKey(array $meta, array $raw, array $index, ?int $keyAt): ?array
    {
        $mode = $meta['key']['mode'];
        if ($mode === 'pk' || $mode === 'unique') {
            $values = [];
            foreach ($meta['key']['cols'] as $name) {
                $i = $index[$name];
                $v = $raw[$i] ?? null;
                $values[] = $v !== null && $meta['columns'][$i]['cat'] === 'bin'
                    ? ['x' => strtolower((string) preg_replace('/^0x/i', '', $v))]
                    : $v;
            }

            return $values;
        }
        if ($keyAt !== null) {
            return [$raw[$keyAt] ?? null];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function keyWhere(array $meta, mixed $key): string
    {
        $mode = $meta['key']['mode'];
        if (! is_array($key)) {
            throw new DbmException('This row has no key, so it cannot be changed here. Use the SQL console.', 'input');
        }
        if ($mode === 'pk' || $mode === 'unique') {
            $names = $meta['key']['cols'];
            if (count($key) !== count($names)) {
                throw new DbmException('The table changed since the rows were loaded. Reload them.', 'stale');
            }
            $by = $this->byName($meta['columns']);
            $parts = [];
            foreach (array_values($names) as $k => $name) {
                $parts[] = $this->keyPart($by[$name], $key[$k] ?? null);
            }

            return implode(' AND ', $parts);
        }
        if ($mode === 'hash' || $mode === 'ctid') {
            $v = $key[0] ?? null;
            if (! is_string($v) || $v === '') {
                throw new DbmException('The table changed since the rows were loaded. Reload them.', 'stale');
            }

            return $this->rowKeyMatch($meta, $v);
        }

        throw new DbmException('This '.($meta['table']['type'] ?? 'table').' has no key, so its rows cannot be changed here.', 'input');
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function keyPart(array $c, mixed $v): string
    {
        $q = $this->quoteIdent($c['name']);
        if ($v === null) {
            return $q.' IS NULL';
        }
        if (is_array($v) && isset($v['x'])) {
            return $q.' = '.$this->binaryLiteral($this->hex((string) $v['x']));
        }
        if (! is_scalar($v)) {
            throw new DbmException('Invalid row key.', 'input');
        }

        return $q.' = '.$this->typedLiteral($c, (string) $v);
    }

    protected function hex(string $hex): string
    {
        $hex = (string) preg_replace('/^0x/i', '', $hex);
        if ($hex !== '' && (! ctype_xdigit($hex) || strlen($hex) % 2 !== 0)) {
            throw new DbmException('Invalid hexadecimal value.', 'input');
        }

        return (string) hex2bin($hex);
    }
}
