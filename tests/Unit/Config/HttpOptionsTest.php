<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\Config;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Slimad\ColnectApi\Laravel\Config\HttpOptions;

final class HttpOptionsTest extends TestCase
{
    public function test_it_falls_back_to_the_documented_defaults(): void
    {
        $options = HttpOptions::fromConfig(new Repository([]));

        self::assertSame(30, $options->timeout);
        self::assertSame(10, $options->connectTimeout);
        self::assertSame(3, $options->tries);
        self::assertSame(500, $options->retryDelay);
        self::assertTrue($options->exponentialBackoff);
    }

    public function test_it_reads_every_value(): void
    {
        $options = HttpOptions::fromConfig($this->config([
            'timeout' => 60,
            'connect_timeout' => 15,
            'tries' => 5,
            'retry_delay' => 1000,
            'exponential_backoff' => false,
        ]));

        self::assertSame(60, $options->timeout);
        self::assertSame(15, $options->connectTimeout);
        self::assertSame(5, $options->tries);
        self::assertSame(1000, $options->retryDelay);
        self::assertFalse($options->exponentialBackoff);
    }

    public function test_it_coerces_the_strings_env_hands_over(): void
    {
        $options = HttpOptions::fromConfig($this->config([
            'timeout' => '60',
            'connect_timeout' => '15',
            'tries' => '5',
            'retry_delay' => '1000',
            'exponential_backoff' => 'false',
        ]));

        self::assertSame(60, $options->timeout);
        self::assertSame(15, $options->connectTimeout);
        self::assertSame(5, $options->tries);
        self::assertSame(1000, $options->retryDelay);
        self::assertFalse($options->exponentialBackoff);
    }

    public function test_a_request_can_never_wait_forever(): void
    {
        foreach ([0, -1] as $configured) {
            $options = HttpOptions::fromConfig($this->config(['timeout' => $configured, 'connect_timeout' => $configured]));

            self::assertSame(HttpOptions::DEFAULT_TIMEOUT, $options->timeout);
            self::assertSame(HttpOptions::DEFAULT_CONNECT_TIMEOUT, $options->connectTimeout);
        }

        $options = HttpOptions::fromConfig($this->config(['timeout' => 1, 'connect_timeout' => 1]));

        self::assertSame(1, $options->timeout);
        self::assertSame(1, $options->connectTimeout);
    }

    public function test_it_always_makes_at_least_one_attempt(): void
    {
        foreach ([0, -1] as $configured) {
            self::assertSame(1, HttpOptions::fromConfig($this->config(['tries' => $configured]))->tries);
        }

        self::assertSame(1, HttpOptions::fromConfig($this->config(['tries' => 1]))->tries);
        self::assertSame(2, HttpOptions::fromConfig($this->config(['tries' => 2]))->tries);
    }

    public function test_retrying_without_a_delay_is_allowed_but_a_negative_one_is_not(): void
    {
        self::assertSame(0, HttpOptions::fromConfig($this->config(['retry_delay' => 0]))->retryDelay);
        self::assertSame(0, HttpOptions::fromConfig($this->config(['retry_delay' => -100]))->retryDelay);
        self::assertSame(1, HttpOptions::fromConfig($this->config(['retry_delay' => 1]))->retryDelay);
    }

    public function test_unreadable_values_fall_back_to_the_defaults(): void
    {
        $options = HttpOptions::fromConfig($this->config([
            'timeout' => 'soon',
            'connect_timeout' => [],
            'tries' => 'many',
            'retry_delay' => 'later',
            'exponential_backoff' => 'perhaps',
        ]));

        self::assertSame(HttpOptions::DEFAULT_TIMEOUT, $options->timeout);
        self::assertSame(HttpOptions::DEFAULT_CONNECT_TIMEOUT, $options->connectTimeout);
        self::assertSame(HttpOptions::DEFAULT_TRIES, $options->tries);
        self::assertSame(HttpOptions::DEFAULT_RETRY_DELAY, $options->retryDelay);
        self::assertTrue($options->exponentialBackoff);
    }

    /**
     * @param  array<string, mixed>  $http
     */
    private function config(array $http): Repository
    {
        return new Repository(['colnect' => ['http' => $http]]);
    }
}
