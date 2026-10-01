<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Events;

/**
 * The rate limiter is about to hold a request back for `$seconds` seconds.
 *
 * `$limit` names the window that ran out ("per_second", "per_minute",
 * "per_hour", "per_day") or "retry_after" when Colnect itself asked to slow
 * down with an HTTP 429 response.
 */
final readonly class RequestThrottled
{
    public function __construct(
        public string $limit,
        public int $seconds,
    ) {}
}
