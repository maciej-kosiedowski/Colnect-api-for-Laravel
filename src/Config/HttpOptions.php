<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Config;

use Illuminate\Contracts\Config\Repository;

/**
 * How the connector talks to the Colnect API over HTTP.
 */
final readonly class HttpOptions
{
    public const DEFAULT_TIMEOUT = 30;

    public const DEFAULT_CONNECT_TIMEOUT = 10;

    public const DEFAULT_TRIES = 3;

    public const DEFAULT_RETRY_DELAY = 500;

    public function __construct(
        public int $timeout,
        public int $connectTimeout,
        public int $tries,
        public int $retryDelay,
        public bool $exponentialBackoff,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $reader = new ConfigReader($config);

        return new self(
            self::positive($reader->integer('colnect.http.timeout')) ?? self::DEFAULT_TIMEOUT,
            self::positive($reader->integer('colnect.http.connect_timeout')) ?? self::DEFAULT_CONNECT_TIMEOUT,
            max(1, $reader->integer('colnect.http.tries') ?? self::DEFAULT_TRIES),
            max(0, $reader->integer('colnect.http.retry_delay') ?? self::DEFAULT_RETRY_DELAY),
            $reader->boolean('colnect.http.exponential_backoff') ?? true,
        );
    }

    /**
     * A timeout of zero means "wait forever" to Guzzle - never what anybody
     * wants from a background API call, so it falls back to the default.
     */
    private static function positive(?int $seconds): ?int
    {
        return $seconds !== null && $seconds > 0 ? $seconds : null;
    }
}
