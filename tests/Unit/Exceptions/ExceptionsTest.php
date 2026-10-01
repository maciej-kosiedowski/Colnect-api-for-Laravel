<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slimad\ColnectApi\Exceptions\InvalidArgumentException;
use Slimad\ColnectApi\Laravel\Exceptions\ColnectConfigurationException;
use Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException;

final class ExceptionsTest extends TestCase
{
    public function test_a_missing_value_names_the_key_and_the_environment_variable(): void
    {
        $exception = ColnectConfigurationException::missingValue('colnect.app_id', 'COLNECT_APP_ID');

        self::assertSame(
            'Colnect API is not configured: "colnect.app_id" is empty. Set COLNECT_APP_ID in your .env file or publish and edit config/colnect.php.',
            $exception->getMessage(),
        );
    }

    public function test_an_invalid_value_keeps_the_core_message_and_exception(): void
    {
        $previous = new InvalidArgumentException('Language must be a 2-letter code.');

        $exception = ColnectConfigurationException::invalid($previous);

        self::assertSame(
            'Colnect API is misconfigured: Language must be a 2-letter code. Check config/colnect.php and the COLNECT_* environment variables.',
            $exception->getMessage(),
        );
        self::assertSame($previous, $exception->getPrevious());
    }

    public function test_configuration_errors_are_caught_by_existing_core_handlers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        throw ColnectConfigurationException::missingValue('a', 'B');
    }

    public function test_configuration_errors_are_caught_by_spl_handlers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        throw ColnectConfigurationException::missingValue('a', 'B');
    }

    public function test_an_invalid_value_carries_no_error_code_of_its_own(): void
    {
        self::assertSame(0, ColnectConfigurationException::invalid(new InvalidArgumentException('x', 42))->getCode());
    }

    public function test_an_exhausted_rate_limit_is_a_runtime_error(): void
    {
        $this->expectException(RuntimeException::class);

        throw RateLimitExceededException::forLimit('per_minute', 42, 30);
    }

    public function test_an_exhausted_rate_limit_says_what_ran_out_and_for_how_long(): void
    {
        $exception = RateLimitExceededException::forLimit('per_minute', 42, 30);

        self::assertSame('per_minute', $exception->limit);
        self::assertSame(42, $exception->retryAfter);
        self::assertSame(
            'Colnect API rate limit "per_minute" is exhausted: the next request is allowed in 42 s, longer than the 30 s "colnect.rate_limit.max_wait" allows.',
            $exception->getMessage(),
        );
    }
}
