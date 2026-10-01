<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\RateLimiting;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Slimad\ColnectApi\Laravel\RateLimiting\RetryAfter;

final class RetryAfterTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function test_it_reads_delay_seconds(): void
    {
        self::assertSame(120, RetryAfter::seconds('120', 60));
        self::assertSame(120, RetryAfter::seconds(' 120 ', 60));
        self::assertSame(120, RetryAfter::seconds("120\r\n", 60));
    }

    public function test_it_reads_an_http_date(): void
    {
        self::assertSame(90, RetryAfter::seconds('Thu, 01 Oct 2026 12:01:30 GMT', 60));
    }

    public function test_an_http_date_is_always_utc(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Warsaw');

        try {
            self::assertSame(90, RetryAfter::seconds('Thu, 01 Oct 2026 12:01:30 GMT', 60));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_it_reads_the_first_value_of_a_repeated_header(): void
    {
        self::assertSame(120, RetryAfter::seconds(['120', '300'], 60));
    }

    public function test_it_falls_back_when_the_header_is_missing_or_unreadable(): void
    {
        self::assertSame(60, RetryAfter::seconds(null, 60));
        self::assertSame(60, RetryAfter::seconds('', 60));
        self::assertSame(60, RetryAfter::seconds([], 60));
        self::assertSame(60, RetryAfter::seconds([120], 60));
        self::assertSame(60, RetryAfter::seconds('soon', 60));
        self::assertSame(60, RetryAfter::seconds('-5', 60));
        self::assertSame(60, RetryAfter::seconds('1.5', 60));
        self::assertSame(45, RetryAfter::seconds('2026-10-01T12:01:30Z', 45));
    }

    public function test_it_waits_at_least_one_second(): void
    {
        self::assertSame(1, RetryAfter::seconds('0', 60));
        self::assertSame(1, RetryAfter::seconds('Thu, 01 Oct 2026 11:59:00 GMT', 60), 'a date in the past');
        self::assertSame(1, RetryAfter::seconds(null, 0));
    }

    public function test_it_never_waits_longer_than_a_day(): void
    {
        self::assertSame(86400, RetryAfter::seconds('86400', 60));
        self::assertSame(86400, RetryAfter::seconds('86401', 60));
        self::assertSame(86400, RetryAfter::seconds('Fri, 01 Oct 2027 12:00:00 GMT', 60));
        self::assertSame(86400, RetryAfter::seconds(null, 100000));
    }
}
