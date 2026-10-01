<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\Config;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Slimad\ColnectApi\Laravel\Config\ConnectorOptions;

final class ConnectorOptionsTest extends TestCase
{
    public function test_it_falls_back_to_the_documented_defaults(): void
    {
        $options = ConnectorOptions::fromConfig(new Repository([]));

        self::assertNull($options->appId);
        self::assertNull($options->appSecret);
        self::assertSame('en', $options->language);
        self::assertSame('Laravel (slimad/colnect-api-laravel)', $options->userAgent);
        self::assertFalse($options->isConfigured());
    }

    public function test_it_reads_every_value(): void
    {
        $options = ConnectorOptions::fromConfig(new Repository(['colnect' => [
            'app_id' => 'my-app',
            'app_secret' => 's3cr3t',
            'language' => 'pl',
            'user_agent' => 'Znaczkopol/2.0 (+https://znaczkopol.pl)',
        ]]));

        self::assertSame('my-app', $options->appId);
        self::assertSame('s3cr3t', $options->appSecret);
        self::assertSame('pl', $options->language);
        self::assertSame('Znaczkopol/2.0 (+https://znaczkopol.pl)', $options->userAgent);
        self::assertTrue($options->isConfigured());
    }

    public function test_whitespace_an_env_file_adds_is_ignored(): void
    {
        $options = ConnectorOptions::fromConfig(new Repository(['colnect' => [
            'app_id' => ' my-app ',
            'app_secret' => "s3cr3t\n",
            'language' => ' pl ',
            'user_agent' => "  Znaczkopol/2.0 \n (+https://znaczkopol.pl) ",
        ]]));

        self::assertSame('my-app', $options->appId);
        self::assertSame('s3cr3t', $options->appSecret);
        self::assertSame('pl', $options->language);
        self::assertSame('Znaczkopol/2.0 (+https://znaczkopol.pl)', $options->userAgent);
    }

    public function test_the_default_user_agent_names_the_application(): void
    {
        $options = ConnectorOptions::fromConfig(new Repository(['app' => ['name' => "  My \n Shop "]]));

        self::assertSame('My Shop (slimad/colnect-api-laravel)', $options->userAgent);
    }

    public function test_blank_values_count_as_missing(): void
    {
        $options = ConnectorOptions::fromConfig(new Repository(['colnect' => [
            'app_id' => '  ',
            'app_secret' => '',
            'language' => '',
            'user_agent' => ' ',
        ]]));

        self::assertNull($options->appId);
        self::assertNull($options->appSecret);
        self::assertSame(ConnectorOptions::DEFAULT_LANGUAGE, $options->language);
        self::assertSame('Laravel '.ConnectorOptions::USER_AGENT_SUFFIX, $options->userAgent);
    }

    public function test_it_is_configured_only_with_both_credentials(): void
    {
        self::assertFalse((new ConnectorOptions('id', null, 'en', 'agent'))->isConfigured());
        self::assertFalse((new ConnectorOptions(null, 'secret', 'en', 'agent'))->isConfigured());
        self::assertTrue((new ConnectorOptions('id', 'secret', 'en', 'agent'))->isConfigured());
    }
}
