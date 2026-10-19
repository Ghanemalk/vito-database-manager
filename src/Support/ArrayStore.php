<?php

namespace VitoDbManager\Support;

final class ArrayStore implements MetaStore
{
    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, array $value): void
    {
        $this->items[$key] = $value;
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
