<?php

namespace VitoDbManager\Support;

/**
 * Short-lived cache of table metadata (columns, row key), so browsing a table costs one round trip.
 * Flushed whenever the manager runs a statement that may change the structure.
 */
interface MetaStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $key, array $value): void;

    public function flush(): void;
}
