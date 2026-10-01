<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Config;

use Illuminate\Contracts\Config\Repository;
use Slimad\ColnectApi\Laravel\RateLimiting\Limit;

/**
 * How hard the package lets the application hit the Colnect API.
 */
final readonly class RateLimitOptions
{
    /**
     * The windows a limit can be configured for, in seconds.
     */
    public const WINDOWS = [
        'per_second' => 1,
        'per_minute' => 60,
        'per_hour' => 3600,
        'per_day' => 86400,
    ];

    public const DEFAULT_PREFIX = 'colnect-api';

    public const DEFAULT_MAX_WAIT = 30;

    public const DEFAULT_RETRY_AFTER = 60;

    /**
     * @param  list<Limit>  $limits
     */
    public function __construct(
        public bool $enabled,
        public ?string $cacheStore,
        public string $prefix,
        public array $limits,
        public int $maxWait,
        public int $retryAfter,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $reader = new ConfigReader($config);

        return new self(
            $reader->boolean('colnect.rate_limit.enabled') ?? true,
            $reader->string('colnect.rate_limit.cache_store'),
            $reader->string('colnect.rate_limit.prefix') ?? self::DEFAULT_PREFIX,
            self::limits($reader),
            max(0, $reader->integer('colnect.rate_limit.max_wait') ?? self::DEFAULT_MAX_WAIT),
            max(1, $reader->integer('colnect.rate_limit.retry_after') ?? self::DEFAULT_RETRY_AFTER),
        );
    }

    /**
     * Shortest window first: a burst is cheaper to wait out than a day.
     * A missing, empty or non-positive value means "no limit for this window".
     *
     * @return list<Limit>
     */
    private static function limits(ConfigReader $reader): array
    {
        $limits = [];

        foreach (self::WINDOWS as $name => $seconds) {
            $requests = $reader->integer('colnect.rate_limit.limits.'.$name);

            if ($requests !== null && $requests > 0) {
                $limits[] = new Limit($name, $requests, $seconds);
            }
        }

        return $limits;
    }
}
