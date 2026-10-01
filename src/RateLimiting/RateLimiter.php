<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\RateLimiting;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\InteractsWithTime;
use Slimad\ColnectApi\Laravel\Config\RateLimitOptions;
use Slimad\ColnectApi\Laravel\Contracts\Sleeper;
use Slimad\ColnectApi\Laravel\Events\RequestThrottled;
use Slimad\ColnectApi\Laravel\Events\TooManyRequestsReceived;
use Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException;

/**
 * Keeps the whole application - every web request, queue worker and Artisan
 * command sharing the cache store - within the configured Colnect quota.
 *
 * Two things hold a request back:
 *
 *  - the local windows from "colnect.rate_limit.limits", counted in the cache;
 *  - a cool-down Colnect itself asked for with an HTTP 429 response.
 *
 * The limiter waits for a free slot as long as that fits within
 * "colnect.rate_limit.max_wait", and throws instead of waiting longer.
 */
final class RateLimiter
{
    use InteractsWithTime;

    /**
     * The name a cool-down requested by Colnect is reported under.
     */
    public const RETRY_AFTER = 'retry_after';

    public function __construct(
        private readonly CacheRateLimiter $limiter,
        private readonly CacheRepository $cache,
        private readonly RateLimitOptions $options,
        private readonly Sleeper $sleeper,
        private readonly Dispatcher $events,
    ) {}

    public function isEnabled(): bool
    {
        return $this->options->enabled;
    }

    public function options(): RateLimitOptions
    {
        return $this->options;
    }

    /**
     * Blocks until one more request may be sent and counts it.
     *
     * @throws RateLimitExceededException when the next free slot is further away than "max_wait" allows
     */
    public function acquire(): void
    {
        if (! $this->options->enabled) {
            return;
        }

        $waited = 0;

        while (($throttle = $this->attempt()) !== null) {
            [$limit, $seconds] = $throttle;

            if ($waited + $seconds > $this->options->maxWait) {
                throw RateLimitExceededException::forLimit($limit, $seconds, $this->options->maxWait);
            }

            $this->events->dispatch(new RequestThrottled($limit, $seconds));
            $this->sleeper->sleep($seconds);

            $waited += $seconds;
        }
    }

    /**
     * Pauses every process sharing the cache store, because Colnect answered
     * HTTP 429. A shorter cool-down never cuts a longer one short.
     */
    public function backOff(int $seconds): void
    {
        $seconds = max(1, $seconds);

        $this->events->dispatch(new TooManyRequestsReceived($seconds));

        if (! $this->options->enabled) {
            return;
        }

        $seconds = max($seconds, $this->cooldown());

        $this->cache->put($this->key(self::RETRY_AFTER), $this->currentTime() + $seconds, $seconds);
    }

    /**
     * Seconds left until Colnect accepts requests again after an HTTP 429.
     */
    public function cooldown(): int
    {
        // Redis hands integers back as numeric strings.
        $endsAt = $this->cache->get($this->key(self::RETRY_AFTER));

        return is_numeric($endsAt) ? max(0, (int) $endsAt - $this->currentTime()) : 0;
    }

    /**
     * How much of every configured window is used up right now.
     *
     * @return list<array{limit: Limit, used: int, remaining: int, resets_in: int}>
     */
    public function usage(): array
    {
        $usage = [];

        foreach ($this->options->limits as $limit) {
            $key = $this->key($limit->name);
            $used = $this->limiter->attempts($key);
            $used = is_numeric($used) ? (int) $used : 0;

            $usage[] = [
                'limit' => $limit,
                'used' => $used,
                'remaining' => max(0, $limit->requests - $used),
                'resets_in' => $this->limiter->availableIn($key),
            ];
        }

        return $usage;
    }

    /**
     * Forgets every counter and any cool-down.
     */
    public function clear(): void
    {
        foreach ($this->options->limits as $limit) {
            $this->limiter->clear($this->key($limit->name));
        }

        $this->cache->forget($this->key(self::RETRY_AFTER));
    }

    /**
     * Takes a slot in every window, or reports what to wait for.
     *
     * A window that does have room keeps the slot even when a later window is
     * full. That errs on the side of sending fewer requests, never more, and it
     * keeps every check a single atomic cache increment.
     *
     * @return array{string, int}|null the exhausted limit and the seconds until it frees up
     */
    private function attempt(): ?array
    {
        $cooldown = $this->cooldown();

        if ($cooldown > 0) {
            return [self::RETRY_AFTER, $cooldown];
        }

        foreach ($this->options->limits as $limit) {
            $key = $this->key($limit->name);

            if ($this->limiter->hit($key, $limit->seconds) > $limit->requests) {
                return [$limit->name, max(1, $this->limiter->availableIn($key))];
            }
        }

        return null;
    }

    private function key(string $name): string
    {
        return $this->options->prefix.':'.$name;
    }
}
