<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Exceptions;

use Slimad\ColnectApi\Exceptions\InvalidArgumentException;

/**
 * The package cannot build a connector from the current configuration.
 *
 * It extends the core package's exception, so code that already catches
 * {@see InvalidArgumentException} keeps working.
 */
final class ColnectConfigurationException extends InvalidArgumentException
{
    public static function missingValue(string $key, string $environmentVariable): self
    {
        return new self(\sprintf(
            'Colnect API is not configured: "%s" is empty. Set %s in your .env file or publish and edit config/colnect.php.',
            $key,
            $environmentVariable,
        ));
    }

    public static function invalid(InvalidArgumentException $previous): self
    {
        return new self(\sprintf(
            'Colnect API is misconfigured: %s Check config/colnect.php and the COLNECT_* environment variables.',
            $previous->getMessage(),
        ), 0, $previous);
    }
}
