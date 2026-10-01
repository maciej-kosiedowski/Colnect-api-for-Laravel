<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Feature\Console;

use Saloon\Http\Faking\MockResponse;
use Slimad\ColnectApi\Laravel\Facades\Colnect;
use Slimad\ColnectApi\Laravel\Tests\TestCase;
use Slimad\ColnectApi\Requests\General\GetLanguagesRequest;

final class StatusCommandTest extends TestCase
{
    public function test_it_shows_the_configuration(): void
    {
        $result = $this->runCommand('colnect:status');

        self::assertSame(0, $result['status']);
        self::assertMatchesRegularExpression('/\|\s+Setting\s+\|\s+Value\s+\|/', $result['output']);
        self::assertMatchesRegularExpression('/App ID\s+\|\s+test-app-id\s/', $result['output']);
        self::assertMatchesRegularExpression('/App secret\s+\|\s+set\s/', $result['output']);
        self::assertMatchesRegularExpression('/Language\s+\|\s+en\s/', $result['output']);
        self::assertMatchesRegularExpression('/User agent\s+\|\s+Colnect Test Suite \(slimad\/colnect-api-laravel\)\s/', $result['output']);
        self::assertMatchesRegularExpression('#Base URL\s+\|\s+https://api\.colnect\.net/en/api/test-app-id\s#', $result['output']);
        self::assertMatchesRegularExpression('/Timeout\s+\|\s+30 s \(connect: 10 s\)\s/', $result['output']);
        self::assertMatchesRegularExpression('/Tries\s+\|\s+3\s/', $result['output']);
        self::assertMatchesRegularExpression('/Rate limiting\s+\|\s+enabled\s/', $result['output']);
        self::assertMatchesRegularExpression('/Cache store\s+\|\s+<default>\s/', $result['output']);
        self::assertMatchesRegularExpression('/Limit: per_second\s+\|\s+0 \/ 2 used\s/', $result['output']);
        self::assertMatchesRegularExpression('/Limit: per_minute\s+\|\s+0 \/ 60 used\s/', $result['output']);
        self::assertMatchesRegularExpression('/Retry-After pause\s+\|\s+none\s/', $result['output']);
        self::assertMatchesRegularExpression('/Max wait\s+\|\s+30 s\s/', $result['output']);
    }

    public function test_it_never_prints_the_secret(): void
    {
        $result = $this->runCommand('colnect:status');

        self::assertStringNotContainsString(self::APP_SECRET, $result['output']);
    }

    public function test_it_shows_how_much_of_the_rate_limit_is_used(): void
    {
        $this->config()->set('colnect.rate_limit.cache_store', 'array');
        $this->config()->set('colnect.http.tries', 1);
        Colnect::fake([MockResponse::make([]), MockResponse::make([], 429, ['Retry-After' => '25'])]);

        Colnect::send(new GetLanguagesRequest);
        Colnect::send(new GetLanguagesRequest);

        $result = $this->runCommand('colnect:status');

        self::assertMatchesRegularExpression('/Cache store\s+\|\s+array\s/', $result['output']);
        self::assertMatchesRegularExpression('/Limit: per_second\s+\|\s+2 \/ 2 used, resets in 1 s\s/', $result['output']);
        self::assertMatchesRegularExpression('/Limit: per_minute\s+\|\s+2 \/ 60 used, resets in 60 s\s/', $result['output']);
        self::assertMatchesRegularExpression('/Retry-After pause\s+\|\s+25 s left\s/', $result['output']);
    }

    public function test_it_hides_the_limits_when_rate_limiting_is_off(): void
    {
        $this->config()->set('colnect.rate_limit.enabled', false);

        $result = $this->runCommand('colnect:status');

        self::assertSame(0, $result['status']);
        self::assertMatchesRegularExpression('/Rate limiting\s+\|\s+disabled\s/', $result['output']);
        self::assertStringNotContainsString('Limit:', $result['output']);
        self::assertStringNotContainsString('Cache store', $result['output']);
        self::assertStringNotContainsString('Retry-After pause', $result['output']);
        self::assertStringNotContainsString('Max wait', $result['output']);
    }

    public function test_it_fails_and_explains_when_credentials_are_missing(): void
    {
        $this->config()->set('colnect.app_id', null);
        $this->config()->set('colnect.app_secret', null);

        $result = $this->runCommand('colnect:status');

        self::assertSame(1, $result['status']);
        self::assertMatchesRegularExpression('/App ID\s+\|\s+<not set>\s/', $result['output']);
        self::assertMatchesRegularExpression('/App secret\s+\|\s+<not set>\s/', $result['output']);
        self::assertMatchesRegularExpression('/Base URL\s+\|\s+<unavailable>\s/', $result['output']);
        self::assertStringContainsString('Set COLNECT_APP_ID in your .env file', $result['output']);
    }

    public function test_it_fails_on_malformed_configuration(): void
    {
        $this->config()->set('colnect.language', 'english');

        $result = $this->runCommand('colnect:status');

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Language must be a 2-letter code', $result['output']);
    }
}
