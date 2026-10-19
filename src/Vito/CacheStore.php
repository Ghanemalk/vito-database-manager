<?php

namespace VitoDbManager\Vito;

use Illuminate\Contracts\Cache\Repository;
use VitoDbManager\Support\MetaStore;

/**
 * Table metadata in Vito's cache, one namespace per database. flush() moves to a new generation
 * instead of deleting keys, which works on every cache driver.
 */
final class CacheStore implements MetaStore
{
    private const TTL = 600;

    private ?int $generation = null;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix,
    ) {}

    public function get(string $key): ?array
    {
        $value = $this->cache->get($this->key($key));

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value): void
    {
        $this->cache->put($this->key($key), $value, self::TTL);
    }

    public function flush(): void
    {
        $this->generation = $this->generation() + 1;
        $this->cache->forever($this->prefix.':gen', $this->generation);
    }

    private function key(string $key): string
    {
        return $this->prefix.':'.$this->generation().':'.md5($key);
    }

    private function generation(): int
    {
        return $this->generation ??= (int) $this->cache->get($this->prefix.':gen', 0);
    }
}
