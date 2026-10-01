<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Config;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed access to configuration values that may come straight from env(), and
 * therefore arrive as strings, as null, or as whatever a published config file
 * was edited to hold.
 *
 * Every method answers null when the value is missing or unusable, so callers
 * decide on the fallback explicitly.
 */
final readonly class ConfigReader
{
    public function __construct(private Repository $config) {}

    /**
     * A trimmed, non-empty string.
     */
    public function string(string $key): ?string
    {
        $value = $this->config->get($key);

        if (! \is_string($value) && ! \is_int($value) && ! \is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * An integer, also when it is written as a numeric string such as "30".
     */
    public function integer(string $key): ?int
    {
        $value = $this->config->get($key);

        if (\is_int($value)) {
            return $value;
        }

        if (! \is_string($value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }

    /**
     * A boolean, also when it is written as "true", "off", "1" and the like.
     */
    public function boolean(string $key): ?bool
    {
        $value = $this->config->get($key);

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }
}
