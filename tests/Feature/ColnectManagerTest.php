<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Feature;

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Slimad\ColnectApi\ColnectConnector;
use Slimad\ColnectApi\Exceptions\InvalidArgumentException;
use Slimad\ColnectApi\Laravel\ColnectConnectorFactory;
use Slimad\ColnectApi\Laravel\ColnectManager;
use Slimad\ColnectApi\Laravel\Exceptions\ColnectConfigurationException;
use Slimad\ColnectApi\Laravel\Facades\Colnect;
use Slimad\ColnectApi\Laravel\LaravelColnectConnector;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;
use Slimad\ColnectApi\Laravel\Tests\TestCase;
use Slimad\ColnectApi\Requests\General\GetCategoriesRequest;
use Slimad\ColnectApi\Requests\General\GetLanguagesRequest;

final class ColnectManagerTest extends TestCase
{
    public function test_the_default_connector_uses_the_configured_language(): void
    {
        $this->config()->set('colnect.language', 'pl');

        self::assertSame('pl', $this->manager()->connector()->getLanguage());
    }

    public function test_connectors_are_reused_per_language(): void
    {
        $manager = $this->manager();

        self::assertSame($manager->connector(), $manager->connector());
        self::assertSame($manager->connector(), $manager->connector('en'));
        self::assertSame($manager->connector('pl'), $manager->connector('pl'));
        self::assertNotSame($manager->connector('en'), $manager->connector('pl'));
        self::assertSame('de', $manager->connector('de')->getLanguage());
    }

    public function test_every_language_shares_one_rate_limiter(): void
    {
        $manager = $this->manager();

        self::assertSame($manager->rateLimiter(), $manager->connector('en')->rateLimiter());
        self::assertSame($manager->rateLimiter(), $manager->connector('pl')->rateLimiter());
        self::assertSame($this->container()->make(RateLimiter::class), $manager->rateLimiter());
    }

    public function test_send_goes_through_the_default_connector(): void
    {
        $mock = Colnect::fake([MockResponse::make(['stamps', 'coins'])]);

        $response = $this->manager()->send(new GetCategoriesRequest);

        self::assertSame(['stamps', 'coins'], $response->json());
        $mock->assertSent(static fn (mixed $request, Response $response): bool => str_starts_with(
            $response->getPendingRequest()->getUrl(),
            'https://api.colnect.net/en/api/'.self::APP_ID.'/categories',
        ));
    }

    public function test_send_async_goes_through_the_default_connector(): void
    {
        Colnect::fake([MockResponse::make(['en' => 'English'])]);

        $response = $this->manager()->sendAsync(new GetLanguagesRequest)->wait();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(['en' => 'English'], $response->json());
    }

    public function test_missing_credentials_are_reported_when_a_connector_is_needed(): void
    {
        $this->config()->set('colnect.app_id', null);

        $this->expectException(ColnectConfigurationException::class);
        $this->expectExceptionMessage('"colnect.app_id" is empty. Set COLNECT_APP_ID');

        $this->manager()->connector();
    }

    public function test_the_factory_never_invents_credentials_unless_asked_to(): void
    {
        $this->config()->set('colnect.app_id', null);

        $this->expectException(ColnectConfigurationException::class);

        $this->container()->make(ColnectConnectorFactory::class)->make();
    }

    public function test_a_missing_secret_is_reported(): void
    {
        $this->config()->set('colnect.app_secret', '');

        $this->expectException(ColnectConfigurationException::class);
        $this->expectExceptionMessage('"colnect.app_secret" is empty. Set COLNECT_APP_SECRET');

        $this->manager()->connector();
    }

    public function test_malformed_values_are_reported_as_configuration_errors(): void
    {
        $this->config()->set('colnect.app_id', 'not/an/id');

        try {
            $this->manager()->connector();
            self::fail('A malformed app ID was accepted.');
        } catch (ColnectConfigurationException $exception) {
            self::assertStringStartsWith('Colnect API is misconfigured: App ID must contain only', $exception->getMessage());
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    public function test_a_malformed_language_is_reported(): void
    {
        $this->expectException(ColnectConfigurationException::class);
        $this->expectExceptionMessage('Language must be a 2-letter code');

        $this->manager()->connector('english');
    }

    public function test_a_too_short_user_agent_is_reported(): void
    {
        $this->config()->set('colnect.user_agent', 'MyApp/1.0');

        $this->expectException(ColnectConfigurationException::class);
        $this->expectExceptionMessage('User agent must be at least 16 characters long');

        $this->manager()->connector();
    }

    public function test_the_package_boots_without_credentials(): void
    {
        $this->config()->set('colnect.app_id', null);
        $this->config()->set('colnect.app_secret', null);

        self::assertFalse($this->manager()->isFaked());
        self::assertTrue($this->manager()->rateLimiter()->isEnabled());
    }

    public function test_fake_answers_from_the_mock_client(): void
    {
        $mock = Colnect::fake([GetLanguagesRequest::class => MockResponse::make(['pl' => 'Polski'])]);

        self::assertInstanceOf(MockClient::class, $mock);
        self::assertTrue(Colnect::isFaked());
        self::assertSame(['pl' => 'Polski'], Colnect::send(new GetLanguagesRequest)->json());
        $mock->assertSent(GetLanguagesRequest::class);
        $mock->assertSentCount(1);
    }

    public function test_fake_does_not_need_credentials(): void
    {
        $this->config()->set('colnect.app_id', null);
        $this->config()->set('colnect.app_secret', null);

        Colnect::fake([MockResponse::make(['en' => 'English'])]);

        $connector = Colnect::connector();

        self::assertSame(ColnectConnectorFactory::FAKE_APP_ID, $connector->getAppId());
        self::assertSame(['en' => 'English'], $connector->send(new GetLanguagesRequest)->json());
    }

    public function test_fake_prefers_real_credentials_when_there_are_some(): void
    {
        Colnect::fake();

        self::assertSame(self::APP_ID, Colnect::connector()->getAppId());
    }

    public function test_fake_reaches_connectors_built_before_and_after_it(): void
    {
        $before = $this->manager()->connector('en');

        $mock = Colnect::fake([
            MockResponse::make(['en' => 'English']),
            MockResponse::make(['pl' => 'Polski']),
        ]);

        $before->send(new GetLanguagesRequest);
        $this->manager()->connector('pl')->send(new GetLanguagesRequest);

        $mock->assertSentCount(2);
        self::assertSame($mock, $before->getMockClient());
        self::assertSame($mock, $this->manager()->connector('pl')->getMockClient());
    }

    public function test_a_second_fake_replaces_the_first(): void
    {
        $first = Colnect::fake([MockResponse::make([])]);
        $second = Colnect::fake([MockResponse::make(['second' => true])]);

        self::assertSame(['second' => true], Colnect::send(new GetLanguagesRequest)->json());
        $first->assertNothingSent();
        $second->assertSentCount(1);
    }

    public function test_the_connector_injected_into_a_class_is_faked_too(): void
    {
        $injected = $this->container()->make(ColnectConnector::class);
        $mock = Colnect::fake([MockResponse::make([])]);

        $injected->send(new GetLanguagesRequest);

        $mock->assertSentCount(1);
    }

    public function test_faked_requests_carry_the_signature_headers(): void
    {
        $mock = Colnect::fake([MockResponse::make([])]);

        Colnect::send(new GetLanguagesRequest);

        $pending = $mock->getLastPendingRequest();
        self::assertInstanceOf(PendingRequest::class, $pending);
        self::assertNotNull($pending->headers()->get('Capi-Hash'));
    }

    public function test_the_facade_and_the_container_share_one_manager(): void
    {
        self::assertSame($this->manager(), Colnect::getFacadeRoot());
        self::assertInstanceOf(LaravelColnectConnector::class, Colnect::connector());
    }

    private function manager(): ColnectManager
    {
        return $this->container()->make(ColnectManager::class);
    }
}
