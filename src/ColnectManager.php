<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel;

use GuzzleHttp\Promise\PromiseInterface;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\Fixture;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;

/**
 * The application-facing entry point, also reachable through the `Colnect`
 * facade.
 *
 * Connectors are built lazily, one per language, and reused: building one needs
 * complete credentials, and the package has to boot on an application that has
 * not configured them yet.
 */
final class ColnectManager
{
    /** @var array<string, LaravelColnectConnector> */
    private array $connectors = [];

    private ?MockClient $mockClient = null;

    public function __construct(
        private readonly ColnectConnectorFactory $factory,
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * The connector for a language, or for "colnect.language" when none is given.
     *
     * @throws Exceptions\ColnectConfigurationException when the package is not configured
     */
    public function connector(?string $language = null): LaravelColnectConnector
    {
        $language ??= $this->factory->defaultLanguage();

        return $this->connectors[$language] ??= $this->make($language);
    }

    /**
     * Sends a request through the default connector.
     *
     * @throws Exceptions\RateLimitExceededException when the rate limit leaves no room within "max_wait"
     * @throws FatalRequestException when Colnect cannot be reached
     */
    public function send(Request $request): Response
    {
        return $this->connector()->send($request);
    }

    public function sendAsync(Request $request): PromiseInterface
    {
        return $this->connector()->sendAsync($request);
    }

    /**
     * Answers every request from a Saloon mock client instead of the network,
     * for this connector and every connector built from now on. Credentials are
     * not required while faking.
     *
     * @param  array<array-key, MockResponse|Fixture|callable>  $responses
     */
    public function fake(array $responses = []): MockClient
    {
        $this->mockClient = new MockClient($responses);

        foreach ($this->connectors as $connector) {
            $connector->withMockClient($this->mockClient);
        }

        return $this->mockClient;
    }

    public function isFaked(): bool
    {
        return $this->mockClient !== null;
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    private function make(string $language): LaravelColnectConnector
    {
        $connector = $this->factory->make($language, $this->mockClient !== null);

        if ($this->mockClient !== null) {
            $connector->withMockClient($this->mockClient);
        }

        return $connector;
    }
}
