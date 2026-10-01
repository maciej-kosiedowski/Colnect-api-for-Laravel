<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\RateLimiting;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Slimad\ColnectApi\Laravel\Config\RateLimitOptions;
use Slimad\ColnectApi\Laravel\Events\RequestThrottled;
use Slimad\ColnectApi\Laravel\Events\TooManyRequestsReceived;
use Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException;
use Slimad\ColnectApi\Laravel\RateLimiting\Limit;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;
use Slimad\ColnectApi\Laravel\Tests\Support\FakeSleeper;

final class RateLimiterTest extends TestCase
{
    private Repository $cache;

    private FakeSleeper $sleeper;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');

        $this->cache = new Repository(new ArrayStore);
        $this->sleeper = new FakeSleeper;
        $this->events = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function test_requests_within_the_limit_go_through_at_once(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 3, 60)]);

        $limiter->acquire();
        $limiter->acquire();
        $limiter->acquire();

        self::assertSame([], $this->sleeper->slept);
        self::assertSame([], $this->events);
    }

    public function test_a_full_window_is_waited_out(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 2, 60)], maxWait: 60);

        $limiter->acquire();
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        $limiter->acquire();
        $limiter->acquire();

        self::assertSame([40], $this->sleeper->slept, 'the window opened 20 s ago and lasts 60 s');
        self::assertEquals([new RequestThrottled('per_minute', 40)], $this->events);
    }

    public function test_the_wait_is_counted_against_the_new_window(): void
    {
        $limiter = $this->limiter([new Limit('per_second', 1, 1)], maxWait: 10);

        $limiter->acquire();
        $limiter->acquire();
        $limiter->acquire();

        self::assertSame([1, 1], $this->sleeper->slept);
        self::assertSame(1, $limiter->usage()[0]['used']);
    }

    public function test_a_window_whose_time_is_up_starts_over_even_if_the_store_lags(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 2, 60)], maxWait: 0);

        $limiter->acquire();
        $limiter->acquire();
        // The window's timer has run out, but the store has not dropped the
        // counter yet - as Laravel 9's array store does for a whole second.
        $this->cache->put('colnect-api:per_minute:timer', Carbon::now()->getTimestamp(), 60);
        $limiter->acquire();

        self::assertSame([], $this->sleeper->slept);
        self::assertSame(1, $limiter->usage()[0]['used'], 'the request counts in the new window');
        self::assertSame(60, $limiter->usage()[0]['resets_in']);
    }

    public function test_every_window_is_respected(): void
    {
        $limiter = $this->limiter([
            new Limit('per_second', 10, 1),
            new Limit('per_minute', 2, 60),
        ], maxWait: 60);

        $limiter->acquire();
        $limiter->acquire();
        $limiter->acquire();

        self::assertSame([60], $this->sleeper->slept);
        self::assertEquals([new RequestThrottled('per_minute', 60)], $this->events);
    }

    public function test_it_throws_instead_of_waiting_longer_than_allowed(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 1, 60)], maxWait: 59);
        $limiter->acquire();

        try {
            $limiter->acquire();
            self::fail('The limiter waited longer than max_wait allows.');
        } catch (RateLimitExceededException $exception) {
            self::assertSame('per_minute', $exception->limit);
            self::assertSame(60, $exception->retryAfter);
        }

        self::assertSame([], $this->sleeper->slept, 'it does not wait part of the way first');
        self::assertSame([], $this->events);
    }

    public function test_a_wait_of_exactly_max_wait_is_still_allowed(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 1, 60)], maxWait: 60);

        $limiter->acquire();
        $limiter->acquire();

        self::assertSame([60], $this->sleeper->slept);
    }

    public function test_max_wait_covers_every_wait_of_one_request_together(): void
    {
        $limiter = $this->limiter([
            new Limit('per_second', 1, 1),
            new Limit('per_minute', 2, 60),
        ], maxWait: 30);

        $limiter->acquire();
        $limiter->acquire();

        $this->expectException(RateLimitExceededException::class);

        // per_second costs 1 s, after which per_minute needs another 59 s.
        $limiter->acquire();
    }

    public function test_max_wait_caps_the_sum_of_all_waits_not_each_one(): void
    {
        $limiter = $this->limiter([], maxWait: 25);
        $limiter->backOff(10);

        // Another worker keeps receiving 429s while this one waits - a few
        // times, so a limiter that forgets earlier waits ends instead of spinning.
        $this->sleeper->whileSleeping = function () use ($limiter): void {
            if (\count($this->sleeper->slept) < 5) {
                $limiter->backOff(10);
            }
        };

        try {
            $limiter->acquire();
            self::fail('The limiter waited longer than max_wait in total.');
        } catch (RateLimitExceededException $exception) {
            self::assertSame(10, $exception->retryAfter);
        }

        self::assertSame([10, 10], $this->sleeper->slept);
    }

    public function test_max_wait_of_zero_never_waits(): void
    {
        $limiter = $this->limiter([new Limit('per_second', 1, 1)], maxWait: 0);
        $limiter->acquire();

        $this->expectException(RateLimitExceededException::class);

        $limiter->acquire();
    }

    public function test_a_disabled_limiter_never_holds_anything_back(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 1, 60)], enabled: false);
        $limiter->backOff(120);

        $limiter->acquire();
        $limiter->acquire();

        self::assertFalse($limiter->isEnabled());
        self::assertSame([], $this->sleeper->slept);
        self::assertSame(0, $limiter->cooldown());
        self::assertSame(0, $limiter->usage()[0]['used']);
    }

    public function test_a_429_pauses_every_request_for_the_retry_after_period(): void
    {
        $limiter = $this->limiter([], maxWait: 60);

        $limiter->backOff(45);

        self::assertSame(45, $limiter->cooldown());

        $limiter->acquire();

        self::assertSame([45], $this->sleeper->slept);
        self::assertSame(0, $limiter->cooldown());
        self::assertEquals([
            new TooManyRequestsReceived(45),
            new RequestThrottled(RateLimiter::RETRY_AFTER, 45),
        ], $this->events);
    }

    public function test_a_429_pause_longer_than_max_wait_throws(): void
    {
        $limiter = $this->limiter([], maxWait: 30);
        $limiter->backOff(31);

        try {
            $limiter->acquire();
            self::fail('The limiter ignored the Retry-After pause.');
        } catch (RateLimitExceededException $exception) {
            self::assertSame(RateLimiter::RETRY_AFTER, $exception->limit);
            self::assertSame(31, $exception->retryAfter);
        }
    }

    public function test_the_pause_is_shared_through_the_cache(): void
    {
        $this->limiter([])->backOff(45);

        self::assertSame(45, $this->limiter([])->cooldown(), 'a second worker sees the pause');
    }

    public function test_a_shorter_pause_never_cuts_a_longer_one_short(): void
    {
        $limiter = $this->limiter([]);

        $limiter->backOff(100);
        $limiter->backOff(10);

        self::assertSame(100, $limiter->cooldown());

        $limiter->backOff(101);

        self::assertSame(101, $limiter->cooldown());
    }

    public function test_the_pause_runs_out(): void
    {
        $limiter = $this->limiter([]);
        $limiter->backOff(10);

        Carbon::setTestNow(Carbon::now()->addSeconds(4));
        self::assertSame(6, $limiter->cooldown());

        Carbon::setTestNow(Carbon::now()->addSeconds(6));
        self::assertSame(0, $limiter->cooldown());

        Carbon::setTestNow(Carbon::now()->addSeconds(100));
        self::assertSame(0, $limiter->cooldown(), 'never negative');
    }

    public function test_a_pause_lasts_at_least_a_second(): void
    {
        $limiter = $this->limiter([]);
        $limiter->backOff(0);

        self::assertSame(1, $limiter->cooldown());
        self::assertEquals([new TooManyRequestsReceived(1)], $this->events);
    }

    public function test_a_pause_stored_as_a_numeric_string_is_understood(): void
    {
        $this->cache->put('colnect-api:retry_after', (string) (Carbon::now()->getTimestamp() + 30), 30);

        self::assertSame(30, $this->limiter([])->cooldown(), 'Redis hands integers back as strings');
    }

    public function test_a_pause_that_ended_is_over_even_if_the_cache_still_holds_it(): void
    {
        $this->cache->put('colnect-api:retry_after', Carbon::now()->getTimestamp() - 5, 60);
        $limiter = $this->limiter([]);

        self::assertSame(0, $limiter->cooldown());

        $limiter->acquire();

        self::assertSame([], $this->sleeper->slept);
    }

    public function test_a_pause_stored_as_a_float_is_understood(): void
    {
        $this->cache->put('colnect-api:retry_after', Carbon::now()->getTimestamp() + 30.5, 60);

        self::assertSame(30, $this->limiter([])->cooldown());
    }

    public function test_a_corrupted_pause_is_ignored(): void
    {
        $this->cache->put('colnect-api:retry_after', 'garbage', 30);
        $limiter = $this->limiter([]);

        self::assertSame(0, $limiter->cooldown());

        $limiter->acquire();

        self::assertSame([], $this->sleeper->slept);
    }

    public function test_the_pause_leaves_nothing_behind_in_the_cache(): void
    {
        $limiter = $this->limiter([]);
        $limiter->backOff(10);
        $limiter->backOff(5);

        Carbon::setTestNow(Carbon::now()->addSeconds(9));
        self::assertTrue($this->cache->has('colnect-api:retry_after'));

        // One second of slack for stores that expire keys a second late.
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        self::assertFalse($this->cache->has('colnect-api:retry_after'));
    }

    public function test_usage_stored_as_a_numeric_string_is_understood(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 60, 60)]);
        $this->cache->put('colnect-api:per_minute', '5', 60);

        self::assertSame(5, $limiter->usage()[0]['used']);
        self::assertSame(55, $limiter->usage()[0]['remaining']);
    }

    public function test_corrupted_usage_counts_as_nothing_used(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 60, 60)]);
        $this->cache->put('colnect-api:per_minute', 'garbage', 60);

        self::assertSame(0, $limiter->usage()[0]['used']);
        self::assertSame(60, $limiter->usage()[0]['remaining']);
    }

    public function test_it_reports_the_usage_of_every_window(): void
    {
        $limiter = $this->limiter([
            new Limit('per_second', 5, 1),
            new Limit('per_minute', 60, 60),
        ]);

        self::assertSame([
            ['limit' => $limiter->options()->limits[0], 'used' => 0, 'remaining' => 5, 'resets_in' => 0],
            ['limit' => $limiter->options()->limits[1], 'used' => 0, 'remaining' => 60, 'resets_in' => 0],
        ], $limiter->usage());

        $limiter->acquire();
        Carbon::setTestNow(Carbon::now()->addSeconds(15));
        $limiter->acquire();

        self::assertSame([
            ['limit' => $limiter->options()->limits[0], 'used' => 1, 'remaining' => 4, 'resets_in' => 1],
            ['limit' => $limiter->options()->limits[1], 'used' => 2, 'remaining' => 58, 'resets_in' => 45],
        ], $limiter->usage());
    }

    public function test_remaining_never_goes_negative(): void
    {
        $limiter = $this->limiter([new Limit('per_minute', 1, 60)], maxWait: 0);
        $limiter->acquire();

        try {
            $limiter->acquire();
        } catch (RateLimitExceededException) {
            // the refused attempt is still counted
        }

        self::assertSame(2, $limiter->usage()[0]['used']);
        self::assertSame(0, $limiter->usage()[0]['remaining']);
    }

    public function test_windows_are_isolated_by_prefix(): void
    {
        $first = $this->limiter([new Limit('per_minute', 1, 60)], prefix: 'first');
        $second = $this->limiter([new Limit('per_minute', 1, 60)], prefix: 'second');

        $first->acquire();
        $second->acquire();
        $first->backOff(30);

        self::assertSame([], $this->sleeper->slept);
        self::assertSame(1, $first->usage()[0]['used']);
        self::assertSame(1, $second->usage()[0]['used']);
        self::assertSame(0, $second->cooldown());
        self::assertTrue($this->cache->has('first:per_minute'));
        self::assertTrue($this->cache->has('first:retry_after'));
    }

    public function test_clear_forgets_every_counter_and_the_pause(): void
    {
        $limiter = $this->limiter([
            new Limit('per_second', 1, 1),
            new Limit('per_minute', 1, 60),
        ], maxWait: 0);
        $limiter->acquire();
        $limiter->backOff(30);

        $limiter->clear();

        self::assertSame(0, $limiter->cooldown());
        self::assertSame(0, $limiter->usage()[0]['used']);
        self::assertSame(0, $limiter->usage()[1]['used']);

        $limiter->acquire();

        self::assertSame([], $this->sleeper->slept);
    }

    /**
     * @param  list<Limit>  $limits
     */
    private function limiter(array $limits, int $maxWait = 30, bool $enabled = true, string $prefix = 'colnect-api'): RateLimiter
    {
        $events = new Dispatcher;
        $events->listen('*', function (string $name, array $payload): void {
            foreach ($payload as $event) {
                if (\is_object($event)) {
                    $this->events[] = $event;
                }
            }
        });

        return new RateLimiter(
            new CacheRateLimiter($this->cache),
            $this->cache,
            new RateLimitOptions($enabled, null, $prefix, $limits, $maxWait, 60),
            $this->sleeper,
            $events,
        );
    }
}
