<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\RateLimiting;

/**
 * At most `$requests` requests per window of `$seconds` seconds.
 */
final readonly class Limit
{
    public function __construct(
        public string $name,
        public int $requests,
        public int $seconds,
    ) {}
}
