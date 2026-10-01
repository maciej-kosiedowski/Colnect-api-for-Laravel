<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;
use RuntimeException;
use Slimad\ColnectApi\Laravel\ColnectServiceProvider;
use Slimad\ColnectApi\Laravel\Contracts\Sleeper;
use Slimad\ColnectApi\Laravel\Facades\Colnect;
use Slimad\ColnectApi\Laravel\Tests\Support\FakeSleeper;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class TestCase extends Orchestra
{
    public const APP_ID = 'test-app-id';

    public const APP_SECRET = 'test-app-secret';

    public const NOW = '2026-10-01 12:00:00';

    protected FakeSleeper $sleeper;

    protected function setUp(): void
    {
        Carbon::setTestNow(self::NOW);
        self::forgetAboutSections();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ColnectServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Colnect' => Colnect::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);

        $config->set('app.name', 'Colnect Test Suite');
        $config->set('cache.default', 'array');

        $config->set('colnect.app_id', self::APP_ID);
        $config->set('colnect.app_secret', self::APP_SECRET);
        $config->set('colnect.http.retry_delay', 0);

        // Registered before the provider resolves anything, so the package keeps
        // an explicitly bound sleeper instead of its native one.
        $this->sleeper = new FakeSleeper;
        $app->instance(Sleeper::class, $this->sleeper);
    }

    /**
     * Sections registered with `php artisan about` are static and would point
     * at the previous test's container. Reset through reflection, because
     * Laravel 9 has no AboutCommand::flushState() yet.
     */
    private static function forgetAboutSections(): void
    {
        foreach (['data', 'customDataResolvers'] as $property) {
            (new ReflectionProperty(AboutCommand::class, $property))->setValue(null, []);
        }
    }

    protected function container(): Application
    {
        $app = $this->app;

        if (! $app instanceof Application) {
            throw new RuntimeException('The Testbench application has not been created yet.');
        }

        return $app;
    }

    protected function config(): Repository
    {
        return $this->container()->make(Repository::class);
    }

    /**
     * `$this->artisan()` is typed as PendingCommand|int; tests always run with
     * a mocked console, so narrow it once here instead of in every assertion.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            throw new RuntimeException('Artisan did not return a pending command.');
        }

        return $pending;
    }

    /**
     * Runs an Artisan command and captures everything it printed, for commands
     * that render tables.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{status: int, output: string}
     */
    protected function runCommand(string $command, array $parameters = []): array
    {
        $output = new BufferedOutput;

        $status = $this->container()->make(Kernel::class)->call($command, $parameters, $output);

        return ['status' => $status, 'output' => $output->fetch()];
    }
}
