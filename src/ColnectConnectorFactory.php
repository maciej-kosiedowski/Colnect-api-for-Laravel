<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel;

use Slimad\ColnectApi\Exceptions\InvalidArgumentException;
use Slimad\ColnectApi\Laravel\Config\ConnectorOptions;
use Slimad\ColnectApi\Laravel\Config\HttpOptions;
use Slimad\ColnectApi\Laravel\Exceptions\ColnectConfigurationException;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;

/**
 * Builds connectors from the package configuration. Every connector shares the
 * same rate limiter: the quota belongs to the application, not to a language.
 */
final readonly class ColnectConnectorFactory
{
    /**
     * Stand-in credentials for faked connectors, so a test suite does not need
     * real ones. They never reach Colnect: a faked connector answers from its
     * mock client.
     */
    public const FAKE_APP_ID = 'colnect-fake-app-id';

    public const FAKE_APP_SECRET = 'colnect-fake-app-secret';

    public function __construct(
        private ConnectorOptions $options,
        private HttpOptions $http,
        private RateLimiter $rateLimiter,
    ) {}

    /**
     * @param  string|null  $language  a 2-letter code such as "pl", defaults to "colnect.language"
     * @param  bool  $fake  fall back to stand-in credentials when none are configured
     *
     * @throws ColnectConfigurationException when credentials are missing or a value is malformed
     */
    public function make(?string $language = null, bool $fake = false): LaravelColnectConnector
    {
        $appId = $this->options->appId ?? ($fake
            ? self::FAKE_APP_ID
            : throw ColnectConfigurationException::missingValue('colnect.app_id', 'COLNECT_APP_ID'));

        $appSecret = $this->options->appSecret ?? ($fake
            ? self::FAKE_APP_SECRET
            : throw ColnectConfigurationException::missingValue('colnect.app_secret', 'COLNECT_APP_SECRET'));

        try {
            return new LaravelColnectConnector(
                $appId,
                $appSecret,
                $language ?? $this->options->language,
                $this->options->userAgent,
                $this->http,
                $this->rateLimiter,
            );
        } catch (InvalidArgumentException $exception) {
            throw ColnectConfigurationException::invalid($exception);
        }
    }

    public function defaultLanguage(): string
    {
        return $this->options->language;
    }
}
