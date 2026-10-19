<?php

namespace VitoDbManager\Support;

use RuntimeException;
use Throwable;

/**
 * An error the manager reports to the browser as is. `kind` lets the UI react (not_found, input, sql,
 * timeout, readonly, stale, connect, too_large); `extra` carries details such as the SQL error code.
 */
class DbmException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        string $message,
        public readonly string $kind = 'error',
        public readonly array $extra = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'message' => $this->getMessage()] + $this->extra;
    }
}
