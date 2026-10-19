<?php

namespace VitoDbManager\Transport;

final class TransportResult
{
    public function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?int $exitCode,
        public readonly bool $truncated,
        public readonly bool $timedOut,
    ) {}
}
