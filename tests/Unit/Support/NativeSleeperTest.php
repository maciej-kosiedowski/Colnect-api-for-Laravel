<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Slimad\ColnectApi\Laravel\Support\NativeSleeper;

final class NativeSleeperTest extends TestCase
{
    public function test_it_actually_sleeps(): void
    {
        $started = hrtime(true);

        (new NativeSleeper)->sleep(1);

        self::assertGreaterThanOrEqual(1.0, (hrtime(true) - $started) / 1e9);
    }

    public function test_it_returns_at_once_for_nothing_to_wait_for(): void
    {
        $started = hrtime(true);

        (new NativeSleeper)->sleep(0);
        (new NativeSleeper)->sleep(-5);

        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9);
    }
}
