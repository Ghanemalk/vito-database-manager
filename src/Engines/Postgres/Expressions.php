<?php

namespace VitoDbManager\Engines\Postgres;

use VitoDbManager\Support\DbmException;

/**
 * The SQL fragments the shared code asks for: how cells are selected, searched, compared and written.
 *
 * Part of PostgresEngine, which is split by area (see there).
 */
trait Expressions
{
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

        return $c['cat'] === 'geo' && in_array($c['udt'], ['geometry', 'geography'], true)
            ? "char_length(ST_AsEWKT({$q}))"
            : "char_length({$q}::text)";
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
}
