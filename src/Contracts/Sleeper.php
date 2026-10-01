<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Contracts;

/**
 * Puts the current process to sleep while the rate limiter waits for a free
 * slot.
 *
 * Bind your own implementation to make throttling observable or instant in
 * tests, or to yield to an event loop instead of blocking.
 */
interface Sleeper
{
    public function sleep(int $seconds): void;
}
