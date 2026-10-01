<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Slimad\ColnectApi\Laravel\ColnectServiceProvider;
use Slimad\ColnectApi\Laravel\Tests\TestCase;

final class PublishingTest extends TestCase
{
    public function test_the_configuration_can_be_published(): void
    {
        foreach (['colnect', 'colnect-config'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(ColnectServiceProvider::class, $tag);

            self::assertCount(1, $paths, $tag);
            self::assertSame(['colnect.php'], array_map('basename', array_keys($paths)));
            self::assertSame([$this->container()->configPath('colnect.php')], array_values($paths));
        }
    }

    public function test_the_published_file_is_the_one_the_provider_merges(): void
    {
        $paths = ServiceProvider::pathsToPublish(ColnectServiceProvider::class, 'colnect-config');
        $source = array_key_first($paths);

        self::assertIsString($source);
        self::assertFileExists($source);

        /** @var array<string, mixed> $published */
        $published = require $source;

        /** @var array<string, mixed> $merged */
        $merged = $this->config()->get('colnect');

        self::assertSame(
            array_keys($published),
            array_keys($merged),
            'a key that only exists in one of the two would silently fall back to a hard-coded default',
        );
    }

    public function test_the_shipped_configuration_holds_no_credentials(): void
    {
        $paths = ServiceProvider::pathsToPublish(ColnectServiceProvider::class, 'colnect-config');

        /** @var array<string, mixed> $published */
        $published = require (string) array_key_first($paths);

        self::assertNull($published['app_id']);
        self::assertNull($published['app_secret']);
    }
}
