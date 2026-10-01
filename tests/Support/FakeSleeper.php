<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Support;

use Closure;
use Illuminate\Support\Carbon;
use LogicException;
use Slimad\ColnectApi\Laravel\Contracts\Sleeper;

/**
 * Records every pause and moves the test clock forward instead of blocking, so
 * the cache windows expire exactly as they would in real time.
 */
final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    public array $slept = [];

    /** @var (Closure(int): void)|null */
    public ?Closure $whileSleeping = null;

    public function sleep(int $seconds): void
    {
        // A zero-second wait inside the limiter's loop would spin forever.
        if ($seconds < 1) {
            throw new LogicException(\sprintf('Asked to sleep for %d seconds.', $seconds));
        }

        $this->slept[] = $seconds;

        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));

        // Runs once the time has passed, as anything that happened meanwhile.
        if ($this->whileSleeping !== null) {
            ($this->whileSleeping)($seconds);
        }
    }

    public function total(): int
    {
        return array_sum($this->slept);
    }
}
