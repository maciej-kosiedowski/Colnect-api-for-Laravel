<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Exceptions;

use RuntimeException;

/**
 * Sending the request now would break a rate limit, and waiting for the next
 * free slot would take longer than "colnect.rate_limit.max_wait" allows.
 *
 * Nothing was sent. Inside a queued job, release the job for `$retryAfter`
 * seconds instead of failing it.
 */
final class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $limit,
        public readonly int $retryAfter,
    ) {
        parent::__construct($message);
    }

    public static function forLimit(string $limit, int $retryAfter, int $maxWait): self
    {
        return new self(\sprintf(
            'Colnect API rate limit "%s" is exhausted: the next request is allowed in %d s, longer than the %d s "colnect.rate_limit.max_wait" allows.',
            $limit,
            $retryAfter,
            $maxWait,
        ), $limit, $retryAfter);
    }
}
