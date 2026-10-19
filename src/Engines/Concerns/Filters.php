<?php

namespace VitoDbManager\Engines\Concerns;

use VitoDbManager\Support\DbmException;

/**
 * The WHERE and ORDER BY of the grid: the filters, the search box and the sort.
 *
 * Part of Engine, which is split by area (see there).
 */
trait Filters
{
    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, array<string, mixed>>  $by
     */
    protected function whereSql(array $a, array $by): string
    {
        $parts = [];
        foreach ((array) ($a['filters'] ?? []) as $f) {
            if (! is_array($f)) {
                continue;
            }
            $name = (string) ($f['col'] ?? '');
            $c = $by[$name] ?? throw new DbmException("Unknown column {$name} in a filter.", 'input');
            $parts[] = $this->filterSql($c, (string) ($f['op'] ?? '='), $f['value'] ?? null, $f['value2'] ?? null);
        }
        $term = trim((string) ($a['search'] ?? ''));
        if ($term !== '') {
            $or = [];
            foreach ($by as $c) {
                if (($cond = $this->searchCondition($c, $term)) !== null) {
                    $or[] = $cond;
                }
            }
            $parts[] = $or === [] ? '1 = 0' : '('.implode(' OR ', $or).')';
        }

        return $parts === [] ? '' : ' WHERE '.implode(' AND ', $parts);
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function filterSql(array $c, string $op, mixed $value, mixed $value2): string
    {
        $q = $this->quoteIdent($c['name']);
        $lit = fn (mixed $v): string => $this->filterLiteral($c, $v);

        return match ($op) {
            '=', '!=', '<', '<=', '>', '>=' => $q.' '.($op === '!=' ? '<>' : $op).' '.$lit($value),
            'contains' => $this->likeCondition($c, '%'.$this->likeEscape((string) $value).'%', false),
            'not_contains' => $this->likeCondition($c, '%'.$this->likeEscape((string) $value).'%', true),
            'starts' => $this->likeCondition($c, $this->likeEscape((string) $value).'%', false),
            'ends' => $this->likeCondition($c, '%'.$this->likeEscape((string) $value), false),
            'null' => $q.' IS NULL',
            'not_null' => $q.' IS NOT NULL',
            'empty' => '('.$q.' IS NULL OR '.$this->likeCondition($c, '', false).')',
            'not_empty' => '('.$q.' IS NOT NULL AND '.$this->likeCondition($c, '', true).')',
            'in', 'not_in' => $q.($op === 'not_in' ? ' NOT IN (' : ' IN (').implode(', ', array_map($lit, $this->listValues($value))).')',
            'between' => $q.' BETWEEN '.$lit($value).' AND '.$lit($value2),
            'regexp' => $this->regexpCondition($c, (string) $value),
            default => throw new DbmException("Unknown filter {$op}.", 'input'),
        };
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function filterLiteral(array $c, mixed $v): string
    {
        if (! is_scalar($v)) {
            throw new DbmException('A filter needs a value.', 'input');
        }
        $s = (string) $v;
        if ($c['cat'] === 'bin') {
            return $this->binaryLiteral($this->hex(str_replace(' ', '', $s)));
        }

        return $this->typedLiteral($c, $s);
    }

    /**
     * @return list<string>
     */
    protected function listValues(mixed $value): array
    {
        $items = is_array($value) ? $value : str_getcsv((string) $value, ',', '"', '');
        $items = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $items), static fn ($v) => $v !== ''));
        if ($items === []) {
            throw new DbmException('"in" needs at least one value, separated by commas.', 'input');
        }

        return array_slice($items, 0, 1000);
    }

    protected function likeEscape(string $v): string
    {
        // "!" rather than the default backslash, so patterns read the same whatever the session's escaping rules
        return strtr($v, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $by
     * @param  array<string, mixed>  $key
     */
    protected function orderSql(mixed $sort, array $by, array $key): string
    {
        $parts = [];
        $used = [];
        foreach (is_array($sort) ? $sort : [] as $s) {
            $name = is_array($s) ? (string) ($s[0] ?? $s['col'] ?? '') : '';
            if (! isset($by[$name]) || isset($used[$name])) {
                continue;
            }
            $dir = strtolower((string) ($s[1] ?? $s['dir'] ?? 'asc')) === 'desc' ? ' DESC' : ' ASC';
            $parts[] = $this->quoteIdent($name).$dir;
            $used[$name] = true;
        }
        // a stable order for paging: end with the unique key
        if (in_array($key['mode'], ['pk', 'unique'], true)) {
            $dir = $parts === [] ? ' DESC' : ' ASC';
            foreach ($key['cols'] as $name) {
                if (! isset($used[$name])) {
                    $parts[] = $this->quoteIdent($name).$dir;
                }
            }
        }

        return $parts === [] ? '' : ' ORDER BY '.implode(', ', $parts);
    }
}
