<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\Config;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Slimad\ColnectApi\Laravel\Config\RateLimitOptions;
use Slimad\ColnectApi\Laravel\RateLimiting\Limit;

final class RateLimitOptionsTest extends TestCase
{
    public function test_it_falls_back_to_the_documented_defaults(): void
    {
        $options = RateLimitOptions::fromConfig(new Repository([]));

        self::assertTrue($options->enabled);
        self::assertNull($options->cacheStore);
        self::assertSame('colnect-api', $options->prefix);
        self::assertSame([], $options->limits, 'without a published config nothing is guessed');
        self::assertSame(30, $options->maxWait);
        self::assertSame(60, $options->retryAfter);
    }

    public function test_the_shipped_config_file_limits_requests_out_of_the_box(): void
    {
        $options = RateLimitOptions::fromConfig(new Repository(['colnect' => require \dirname(__DIR__, 3).'/config/colnect.php']));

        self::assertEquals([
            new Limit('per_second', 2, 1),
            new Limit('per_minute', 60, 60),
        ], $options->limits);
    }

    public function test_it_reads_every_value(): void
    {
        $options = RateLimitOptions::fromConfig($this->config([
            'enabled' => false,
            'cache_store' => 'redis',
            'prefix' => 'my-app:colnect',
            'limits' => [
                'per_second' => 5,
                'per_minute' => 100,
                'per_hour' => 2000,
                'per_day' => 20000,
            ],
            'max_wait' => 120,
            'retry_after' => 300,
        ]));

        self::assertFalse($options->enabled);
        self::assertSame('redis', $options->cacheStore);
        self::assertSame('my-app:colnect', $options->prefix);
        self::assertEquals([
            new Limit('per_second', 5, 1),
            new Limit('per_minute', 100, 60),
            new Limit('per_hour', 2000, 3600),
            new Limit('per_day', 20000, 86400),
        ], $options->limits);
        self::assertSame(120, $options->maxWait);
        self::assertSame(300, $options->retryAfter);
    }

    public function test_it_coerces_the_strings_env_hands_over(): void
    {
        $options = RateLimitOptions::fromConfig($this->config([
            'enabled' => 'false',
            'cache_store' => ' redis ',
            'limits' => ['per_day' => '20000'],
            'max_wait' => '120',
            'retry_after' => '300',
        ]));

        self::assertFalse($options->enabled);
        self::assertSame('redis', $options->cacheStore);
        self::assertEquals([new Limit('per_day', 20000, 86400)], $options->limits);
        self::assertSame(120, $options->maxWait);
        self::assertSame(300, $options->retryAfter);
    }

    public function test_empty_zero_and_negative_limits_switch_a_window_off(): void
    {
        $options = RateLimitOptions::fromConfig($this->config(['limits' => [
            'per_second' => '',
            'per_minute' => 0,
            'per_hour' => -1,
            'per_day' => 1,
            'per_week' => 10,
        ]]));

        self::assertEquals([new Limit('per_day', 1, 86400)], $options->limits);
    }

    public function test_limits_are_ordered_from_the_shortest_window(): void
    {
        $options = RateLimitOptions::fromConfig($this->config(['limits' => [
            'per_day' => 3,
            'per_hour' => 2,
            'per_second' => 1,
        ]]));

        self::assertSame(['per_second', 'per_hour', 'per_day'], array_map(
            static fn (Limit $limit): string => $limit->name,
            $options->limits,
        ));
    }

    public function test_waiting_can_be_switched_off_but_never_go_negative(): void
    {
        self::assertSame(0, RateLimitOptions::fromConfig($this->config(['max_wait' => 0]))->maxWait);
        self::assertSame(0, RateLimitOptions::fromConfig($this->config(['max_wait' => -10]))->maxWait);
        self::assertSame(1, RateLimitOptions::fromConfig($this->config(['max_wait' => 1]))->maxWait);
    }

    public function test_a_retry_after_pause_lasts_at_least_a_second(): void
    {
        self::assertSame(1, RateLimitOptions::fromConfig($this->config(['retry_after' => 0]))->retryAfter);
        self::assertSame(1, RateLimitOptions::fromConfig($this->config(['retry_after' => -10]))->retryAfter);
        self::assertSame(2, RateLimitOptions::fromConfig($this->config(['retry_after' => 2]))->retryAfter);
    }

    public function test_blank_values_fall_back_to_the_defaults(): void
    {
        $options = RateLimitOptions::fromConfig($this->config([
            'enabled' => null,
            'cache_store' => '',
            'prefix' => '  ',
        ]));

        self::assertTrue($options->enabled);
        self::assertNull($options->cacheStore);
        self::assertSame(RateLimitOptions::DEFAULT_PREFIX, $options->prefix);
    }

    /**
     * @param  array<string, mixed>  $rateLimit
     */
    private function config(array $rateLimit): Repository
    {
        return new Repository(['colnect' => ['rate_limit' => $rateLimit]]);
    }
}
