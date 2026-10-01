<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Slimad\ColnectApi\Laravel\ColnectManager;

/**
 * @method static \Slimad\ColnectApi\Laravel\LaravelColnectConnector connector(?string $language = null)
 * @method static \Saloon\Http\Response send(\Saloon\Http\Request $request)
 * @method static \GuzzleHttp\Promise\PromiseInterface sendAsync(\Saloon\Http\Request $request)
 * @method static \Saloon\Http\Faking\MockClient fake(array<array-key, \Saloon\Http\Faking\MockResponse|\Saloon\Http\Faking\Fixture|callable> $responses = [])
 * @method static bool isFaked()
 * @method static \Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter rateLimiter()
 *
 * @see ColnectManager
 */
final class Colnect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ColnectManager::class;
    }
}
