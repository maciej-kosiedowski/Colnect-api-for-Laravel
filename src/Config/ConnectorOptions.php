<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Config;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * Who the application is when it talks to Colnect.
 *
 * Credentials stay nullable here: the package has to boot on an application
 * that has installed it but not configured it yet. They are only required once
 * a connector is actually built.
 */
final readonly class ConnectorOptions
{
    public const DEFAULT_LANGUAGE = 'en';

    public const USER_AGENT_SUFFIX = '(slimad/colnect-api-laravel)';

    public function __construct(
        public ?string $appId,
        public ?string $appSecret,
        public string $language,
        public string $userAgent,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $reader = new ConfigReader($config);

        return new self(
            $reader->string('colnect.app_id'),
            $reader->string('colnect.app_secret'),
            $reader->string('colnect.language') ?? self::DEFAULT_LANGUAGE,
            self::squish($reader->string('colnect.user_agent'))
                ?? (self::squish($reader->string('app.name')) ?? 'Laravel').' '.self::USER_AGENT_SUFFIX,
        );
    }

    public function isConfigured(): bool
    {
        return $this->appId !== null && $this->appSecret !== null;
    }

    /**
     * Header values travel on a single line; a stray newline from an .env file
     * must not turn into a rejected - or injected - header.
     */
    private static function squish(?string $value): ?string
    {
        return $value === null ? null : Str::squish($value);
    }
}
