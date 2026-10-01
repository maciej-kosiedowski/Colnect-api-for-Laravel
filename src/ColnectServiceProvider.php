<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Slimad\ColnectApi\ColnectConnector;
use Slimad\ColnectApi\Laravel\Config\ConnectorOptions;
use Slimad\ColnectApi\Laravel\Config\HttpOptions;
use Slimad\ColnectApi\Laravel\Config\RateLimitOptions;
use Slimad\ColnectApi\Laravel\Console\StatusCommand;
use Slimad\ColnectApi\Laravel\Console\UsageCommand;
use Slimad\ColnectApi\Laravel\Contracts\Sleeper;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;
use Slimad\ColnectApi\Laravel\Support\NativeSleeper;

final class ColnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'colnect');

        $this->app->singleton(ConnectorOptions::class, static fn (Container $app): ConnectorOptions => ConnectorOptions::fromConfig(
            $app->make(Repository::class),
        ));

        $this->app->singleton(HttpOptions::class, static fn (Container $app): HttpOptions => HttpOptions::fromConfig(
            $app->make(Repository::class),
        ));

        $this->app->singleton(RateLimitOptions::class, static fn (Container $app): RateLimitOptions => RateLimitOptions::fromConfig(
            $app->make(Repository::class),
        ));

        $this->app->singletonIf(Sleeper::class, NativeSleeper::class);

        $this->app->singleton(RateLimiter::class, static function (Container $app): RateLimiter {
            $options = $app->make(RateLimitOptions::class);
            $cache = $app->make(CacheFactory::class)->store($options->cacheStore);

            return new RateLimiter(
                new CacheRateLimiter($cache),
                $cache,
                $options,
                $app->make(Sleeper::class),
                $app->make(Dispatcher::class),
            );
        });

        $this->app->singleton(ColnectConnectorFactory::class, static fn (Container $app): ColnectConnectorFactory => new ColnectConnectorFactory(
            $app->make(ConnectorOptions::class),
            $app->make(HttpOptions::class),
            $app->make(RateLimiter::class),
        ));

        $this->app->singleton(ColnectManager::class, static fn (Container $app): ColnectManager => new ColnectManager(
            $app->make(ColnectConnectorFactory::class),
            $app->make(RateLimiter::class),
        ));

        $this->app->alias(ColnectManager::class, 'colnect');

        // Not a singleton of its own: the manager already keeps one connector per
        // language, and going through it means a fake installed later still
        // reaches the connector every class was injected with.
        $this->app->bind(ColnectConnector::class, static fn (Container $app): ColnectConnector => $app
            ->make(ColnectManager::class)
            ->connector());

        $this->app->alias(ColnectConnector::class, LaravelColnectConnector::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    private function bootForConsole(): void
    {
        $this->publishes(
            [self::configPath() => $this->app->configPath('colnect.php')],
            ['colnect', 'colnect-config'],
        );

        $this->commands([
            StatusCommand::class,
            UsageCommand::class,
        ]);

        // AboutCommand ships with laravel/framework, not with the illuminate/*
        // components this package depends on.
        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('Colnect', fn (): array => $this->about());
        }
    }

    /**
     * @return array<string, string>
     */
    private function about(): array
    {
        $connector = $this->app->make(ConnectorOptions::class);
        $rateLimit = $this->app->make(RateLimitOptions::class);

        return [
            'Configured' => $connector->isConfigured() ? '<fg=green;options=bold>YES</>' : '<fg=yellow;options=bold>NO</>',
            'Language' => $connector->language,
            'Rate limiting' => $rateLimit->enabled ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>',
        ];
    }

    private static function configPath(): string
    {
        return \dirname(__DIR__).'/config/colnect.php';
    }
}
