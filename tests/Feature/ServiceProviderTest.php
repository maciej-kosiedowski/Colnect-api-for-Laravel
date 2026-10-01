<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\AboutCommand;
use Slimad\ColnectApi\ColnectConnector;
use Slimad\ColnectApi\Laravel\ColnectConnectorFactory;
use Slimad\ColnectApi\Laravel\ColnectManager;
use Slimad\ColnectApi\Laravel\ColnectServiceProvider;
use Slimad\ColnectApi\Laravel\Config\ConnectorOptions;
use Slimad\ColnectApi\Laravel\Config\HttpOptions;
use Slimad\ColnectApi\Laravel\Config\RateLimitOptions;
use Slimad\ColnectApi\Laravel\Contracts\Sleeper;
use Slimad\ColnectApi\Laravel\LaravelColnectConnector;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;
use Slimad\ColnectApi\Laravel\Support\NativeSleeper;
use Slimad\ColnectApi\Laravel\Tests\Support\FakeSleeper;
use Slimad\ColnectApi\Laravel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        AboutCommand::flushState();

        parent::tearDown();
    }

    public function test_it_merges_the_package_configuration(): void
    {
        self::assertSame(30, $this->config()->get('colnect.http.timeout'));
        self::assertSame(60, $this->config()->get('colnect.rate_limit.limits.per_minute'));
    }

    public function test_it_binds_every_service(): void
    {
        self::assertInstanceOf(ConnectorOptions::class, $this->container()->make(ConnectorOptions::class));
        self::assertInstanceOf(HttpOptions::class, $this->container()->make(HttpOptions::class));
        self::assertInstanceOf(RateLimitOptions::class, $this->container()->make(RateLimitOptions::class));
        self::assertInstanceOf(RateLimiter::class, $this->container()->make(RateLimiter::class));
        self::assertInstanceOf(ColnectConnectorFactory::class, $this->container()->make(ColnectConnectorFactory::class));
        self::assertInstanceOf(ColnectManager::class, $this->container()->make(ColnectManager::class));
        self::assertInstanceOf(ColnectManager::class, $this->container()->make('colnect'));
        self::assertInstanceOf(LaravelColnectConnector::class, $this->container()->make(ColnectConnector::class));
    }

    public function test_services_are_singletons(): void
    {
        foreach ([
            ConnectorOptions::class,
            HttpOptions::class,
            RateLimitOptions::class,
            RateLimiter::class,
            ColnectConnectorFactory::class,
            ColnectManager::class,
            Sleeper::class,
        ] as $abstract) {
            self::assertSame($this->container()->make($abstract), $this->container()->make($abstract), $abstract);
        }
    }

    public function test_the_core_and_the_laravel_connector_resolve_to_the_managers_connector(): void
    {
        $manager = $this->container()->make(ColnectManager::class);

        self::assertSame($manager->connector(), $this->container()->make(ColnectConnector::class));
        self::assertSame($manager->connector(), $this->container()->make(LaravelColnectConnector::class));
        self::assertSame($manager, $this->container()->make('colnect'));
    }

    public function test_the_rate_limiter_uses_the_bound_sleeper(): void
    {
        self::assertSame($this->sleeper, $this->container()->make(Sleeper::class));
    }

    public function test_it_sleeps_natively_unless_told_otherwise(): void
    {
        $app = $this->container();
        $app->forgetInstance(Sleeper::class);
        $app->offsetUnset(Sleeper::class);

        (new ColnectServiceProvider($app))->register();

        self::assertInstanceOf(NativeSleeper::class, $app->make(Sleeper::class));
    }

    public function test_a_sleeper_bound_by_the_application_is_kept(): void
    {
        $app = $this->container();
        $sleeper = new FakeSleeper;
        $app->instance(Sleeper::class, $sleeper);

        (new ColnectServiceProvider($app))->register();

        self::assertSame($sleeper, $app->make(Sleeper::class));
    }

    public function test_it_registers_the_artisan_commands(): void
    {
        $commands = array_keys($this->container()->make(Kernel::class)->all());

        self::assertContains('colnect:status', $commands);
        self::assertContains('colnect:usage', $commands);
    }

    public function test_it_reports_itself_in_the_about_command(): void
    {
        $result = $this->runCommand('about', ['--only' => 'colnect', '--json' => true]);

        self::assertSame(0, $result['status']);
        self::assertJsonStringEqualsJsonString(
            '{"colnect":{"configured":"YES","language":"en","rate_limiting":"ENABLED"}}',
            $result['output'],
        );
    }

    public function test_the_about_command_shows_what_is_missing(): void
    {
        $this->config()->set('colnect.app_secret', null);
        $this->config()->set('colnect.language', 'pl');
        $this->config()->set('colnect.rate_limit.enabled', false);

        $result = $this->runCommand('about', ['--only' => 'colnect', '--json' => true]);

        self::assertJsonStringEqualsJsonString(
            '{"colnect":{"configured":"NO","language":"pl","rate_limiting":"DISABLED"}}',
            $result['output'],
        );
    }
}
