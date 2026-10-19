<?php

namespace VitoDbManager\Engines\Mysql;

use VitoDbManager\Support\DbmException;

/**
 * The SQL fragments the shared code asks for: how cells are selected, searched, compared and written.
 *
 * Part of MysqlEngine, which is split by area (see there).
 */
trait Expressions
{
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
}
