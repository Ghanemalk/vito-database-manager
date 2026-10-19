<?php

namespace VitoDbManager\Engines\Concerns;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * Structure changes: ddl() checks what the browser sent and runs the engine's SQL for it (ddlStatements()).
 *
 * Part of Engine, which is split by area (see there).
 */
trait Structure
{
    /**
     * Builds, and unless "dry" runs, the SQL for one structure change made from the UI: create, rename, duplicate,
     * empty or drop a table; add, change or drop a column, an index, a foreign key or a check; table options and
     * maintenance. The UI first asks with "dry" and shows the SQL, then runs exactly that.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function ddl(array $a): array
    {
        [$statements, $tx] = $this->ddlStatements((string) ($a['action'] ?? ''), $a);
        if (! empty($a['dry'])) {
            return ['sql' => $statements];
        }
        $r = $this->execute($statements, ['tx' => $tx, 'timeout' => self::WALL_TIME - 5, 'warnings' => true, 'lock_timeout' => 20]);
        $this->store->flush();
        $results = [];
        foreach ($r['results'] as $k => $res) {
            $results[] = [
                'sql' => $statements[$k],
                'sets' => array_map(fn ($set) => [
                    'columns' => $set['columns'],
                    'rows' => array_map(fn ($row) => array_map(fn ($v) => $this->genericCell($v, 4000), $row), array_slice($set['rows'], 0, 500)),
                    'count' => count($set['rows']),
                    'more' => count($set['rows']) > 500,
                ], $res['sets']),
                'affected' => $res['affected'],
                'warnings' => $res['warnings'],
                'ms' => $res['ms'],
            ];
        }
        if ($r['error'] !== null) {
            $e = $this->sqlException($r['error']);
            throw new DbmException($e->getMessage(), $e->kind, $e->extra + [
                'sql' => $statements[$r['error']['i']] ?? null,
                'done' => count($results),
                'rolled_back' => $tx,
            ]);
        }

        return ['sql' => $statements, 'results' => $results];
    }

    /**
     * A new name for a table, column, index or constraint.
     */
    protected function nameArg(mixed $name, string $what): string
    {
        $max = $this->dialect() === Splitter::PGSQL ? 63 : 64;
        if (! is_string($name) || $name === '' || $name !== trim($name) || strlen($name) > $max || preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw new DbmException("Enter a valid {$what} (at most {$max} characters, no leading or trailing spaces).", 'input');
        }

        return $name;
    }

    /**
     * A column type as typed in the UI: a type name with an optional size, modifiers and array brackets.
     * MySQL ENUM/SET are built here from their values.
     *
     * @param  array<string, mixed>  $spec
     */
    protected function typeSql(array $spec): string
    {
        $type = trim((string) ($spec['type'] ?? ''));
        if ($this->dialect() === Splitter::MYSQL && preg_match('/^(enum|set)$/i', $type) === 1) {
            $options = array_values(array_filter(array_map('strval', (array) ($spec['options'] ?? [])), static fn ($v) => $v !== ''));
            if ($options === []) {
                throw new DbmException('Give the '.strtoupper($type).' column at least one value.', 'input');
            }

            return strtolower($type).'('.implode(', ', array_map(fn ($v) => $this->literal($v), $options)).')';
        }
        if ($type === '' || strlen($type) > 100 || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new DbmException(sprintf('"%s" is not a valid column type.', mb_strimwidth($type, 0, 60, '…')), 'input');
        }

        return $type;
    }

    /**
     * An SQL expression typed in the UI (a default, a CHECK, a generated column, a USING clause).
     */
    protected function exprArg(mixed $expr, string $what): string
    {
        $expr = trim(is_scalar($expr) ? (string) $expr : '');
        if ($expr === '' || ! Splitter::isExpression($expr, $this->dialect())) {
            throw new DbmException("{$what} must be one SQL expression, without comments or semicolons.", 'input');
        }

        return $expr;
    }

    protected function ruleSql(mixed $rule): string
    {
        $r = strtoupper(str_replace('_', ' ', trim((string) $rule)));
        if ($r === '') {
            return '';
        }
        if (! in_array($r, ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT'], true)) {
            throw new DbmException("Unknown foreign key action {$r}.", 'input');
        }

        return $r;
    }

    /**
     * Existing column names, checked against the table.
     *
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    protected function columnList(array $meta, mixed $names, string $what = 'columns'): array
    {
        $by = $this->byName($meta['columns']);
        $out = [];
        foreach (is_array($names) ? $names : [$names] as $n) {
            $n = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
            if (! isset($by[$n])) {
                throw new DbmException("Unknown column {$n}.", 'input');
            }
            $out[] = $n;
        }
        if ($out === []) {
            throw new DbmException("Choose the {$what}.", 'input');
        }

        return $out;
    }

    /**
     * Column specs of a create-table request.
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    protected function specsArg(array $a): array
    {
        $specs = array_values(array_filter((array) ($a['columns'] ?? []), 'is_array'));
        if ($specs === []) {
            throw new DbmException('Add at least one column.', 'input');
        }
        $seen = [];
        foreach ($specs as $s) {
            $name = $this->nameArg($s['name'] ?? null, 'column name');
            if (isset($seen[strtolower($name)])) {
                throw new DbmException("Column {$name} is listed twice.", 'input');
            }
            $seen[strtolower($name)] = true;
        }

        return $specs;
    }

    /**
     * The default of a column spec: none | null | literal | expr.
     *
     * @param  array<string, mixed>  $spec
     * @return array{kind: string, value: ?string}
     */
    protected function defaultArg(array $spec): array
    {
        $d = $spec['default'] ?? null;
        if (! is_array($d)) {
            return ['kind' => 'none', 'value' => null];
        }
        $kind = (string) ($d['kind'] ?? 'none');

        return match ($kind) {
            'none', 'null' => ['kind' => $kind, 'value' => null],
            'literal' => ['kind' => 'literal', 'value' => is_scalar($d['value'] ?? null) ? (string) $d['value'] : ''],
            'expr' => ['kind' => 'expr', 'value' => $this->exprArg($d['value'] ?? '', 'The default')],
            default => throw new DbmException("Unknown default kind {$kind}.", 'input'),
        };
    }

    /**
     * The canonical spelling when $expr is one of functions(), else null.
     */
    protected function knownFunction(string $expr): ?string
    {
        $norm = static fn (string $s): string => strtoupper((string) preg_replace('/\s+/', '', $s));
        foreach ($this->functions() as $f) {
            if ($norm($f) === $norm($expr)) {
                return $f;
            }
        }

        return null;
    }

    protected static function sh(string $s): string
    {
        return "'".str_replace("'", "'\\''", $s)."'";
    }

    protected function jsonValue(string $v): mixed
    {
        try {
            return json_decode($v, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $v;
        }
    }
}
