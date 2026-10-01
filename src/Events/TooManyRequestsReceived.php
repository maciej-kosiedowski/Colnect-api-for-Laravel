<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Events;

/**
 * Colnect answered HTTP 429. Every process sharing the rate limiter's cache
 * store pauses for `$retryAfter` seconds before sending the next request.
 */
final readonly class TooManyRequestsReceived
{
    public function __construct(
        public int $retryAfter,
    ) {}
}
