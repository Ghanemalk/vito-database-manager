<?php

namespace VitoDbManager\Engines\Concerns;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * A table's columns and key, cached in the MetaStore, and the checks on a table named in a request.
 *
 * Part of Engine, which is split by area (see there).
 */
trait Metadata
{
    /**
     * @param  array{schema: ?string, name: string}  $table
     * @return array{table: array<string, mixed>, columns: list<array<string, mixed>>, key: array<string, mixed>}
     */
    public function meta(array $table, bool $fresh = false): array
    {
        $cacheKey = 'meta:'.($table['schema'] ?? '').'.'.$table['name'];
        if (! $fresh && ($cached = $this->store->get($cacheKey)) !== null) {
            return $cached;
        }
        $meta = $this->loadMeta($table);
        $this->store->put($cacheKey, $meta);

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{schema: ?string, name: string}
     */
    protected function tableArg(array $a): array
    {
        $t = $a['table'] ?? null;
        $name = is_array($t) ? ($t['name'] ?? null) : $t;
        $schema = is_array($t) ? ($t['schema'] ?? null) : null;
        if (! is_string($name) || $name === '' || strlen($name) > 128 || str_contains($name, "\0")) {
            throw new DbmException('Choose a table.', 'input');
        }
        if ($schema !== null && (! is_string($schema) || $schema === '' || strlen($schema) > 128 || str_contains($schema, "\0"))) {
            throw new DbmException('Invalid schema.', 'input');
        }

        return ['schema' => $this->dialect() === Splitter::PGSQL ? ($schema ?? 'public') : null, 'name' => $name];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, array<string, mixed>>
     */
    protected function byName(array $columns): array
    {
        $by = [];
        foreach ($columns as $c) {
            $by[$c['name']] = $c;
        }

        return $by;
    }

    protected function int(mixed $value, int $min, int $max, int $default = 0): int
    {
        $n = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $n));
    }
}
