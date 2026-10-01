<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Feature\Console;

use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Slimad\ColnectApi\Laravel\Facades\Colnect;
use Slimad\ColnectApi\Laravel\Tests\TestCase;
use Slimad\ColnectApi\Requests\General\GetRequestCountRequest;

final class UsageCommandTest extends TestCase
{
    public function test_it_lists_the_requests_per_day(): void
    {
        $mock = Colnect::fake([MockResponse::make([['2026-09-30', 1250], ['2026-10-01', 87]])]);

        $result = $this->runCommand('colnect:usage');

        self::assertSame(0, $result['status']);
        self::assertMatchesRegularExpression('/\|\s+Day\s+\|\s+Requests\s+\|/', $result['output']);
        self::assertMatchesRegularExpression('/2026-09-30\s+\|\s+1250\s/', $result['output']);
        self::assertMatchesRegularExpression('/2026-10-01\s+\|\s+87\s/', $result['output']);
        $this->assertRequested($mock, '/request_count/days/7');
    }

    public function test_it_asks_for_the_requested_number_of_days(): void
    {
        $mock = Colnect::fake([MockResponse::make([])]);

        $this->runCommand('colnect:usage', ['--days' => '30']);

        $this->assertRequested($mock, '/request_count/days/30');
    }

    public function test_it_understands_a_single_pair(): void
    {
        Colnect::fake([MockResponse::make(['2026-10-01', 87])]);

        $result = $this->runCommand('colnect:usage', ['--days' => '1']);

        self::assertSame(0, $result['status']);
        self::assertMatchesRegularExpression('/2026-10-01\s+\|\s+87\s/', $result['output']);
    }

    public function test_it_skips_entries_it_does_not_understand(): void
    {
        Colnect::fake([MockResponse::make([
            ['2026-09-29', 10],
            ['2026-09-30'],
            ['day' => '2026-09-30', 'count' => 5],
            [['nested'], 5],
            ['2026-09-30', null],
            ['2026-09-30', ['nested']],
            ['2026-09-30', true],
            [true, 5],
            [1.5, 5],
            'garbage',
            ['2026-10-01', 87],
        ])]);

        $result = $this->runCommand('colnect:usage');

        self::assertSame(0, $result['status']);
        self::assertMatchesRegularExpression('/2026-09-29\s+\|\s+10\s/', $result['output']);
        self::assertMatchesRegularExpression('/2026-10-01\s+\|\s+87\s/', $result['output']);
        self::assertStringNotContainsString('nested', $result['output']);
        self::assertStringNotContainsString('1.5', $result['output']);
        self::assertSame(2, substr_count($result['output'], '2026-'));
    }

    public function test_it_says_so_when_there_is_nothing_to_report(): void
    {
        foreach ([[], '"private"', 'not json', ['unexpected' => 'shape'], [[1, 2, 3]]] as $payload) {
            Colnect::fake([MockResponse::make($payload)]);

            $result = $this->runCommand('colnect:usage');

            self::assertSame(0, $result['status']);
            self::assertStringContainsString('Colnect reported no requests.', $result['output']);
            self::assertStringNotContainsString('Day', $result['output'], 'no empty table');
        }
    }

    public function test_it_rejects_an_invalid_number_of_days(): void
    {
        $mock = Colnect::fake();

        foreach (['0', '-1', '201'] as $days) {
            $this->command('colnect:usage', ['--days' => $days])
                ->expectsOutputToContain('Days must be between 1 and 200.')
                ->assertFailed();
        }

        foreach (['seven', '1.5', ''] as $days) {
            $this->command('colnect:usage', ['--days' => $days])
                ->expectsOutputToContain('The --days option has to be a whole number between 1 and 200.')
                ->assertFailed();
        }

        $mock->assertNothingSent();
    }

    public function test_it_reports_an_error_response(): void
    {
        Colnect::fake([MockResponse::make('  Invalid hash  ', 403)]);

        $this->command('colnect:usage')
            ->expectsOutputToContain('Colnect answered HTTP 403: Invalid hash')
            ->assertFailed();
    }

    public function test_it_ignores_whitespace_around_an_error_body(): void
    {
        Colnect::fake([MockResponse::make(str_repeat(' ', 250)."Invalid hash\n", 403)]);

        $this->command('colnect:usage')
            ->expectsOutputToContain('Colnect answered HTTP 403: Invalid hash')
            ->assertFailed();
    }

    public function test_it_truncates_a_long_error_body(): void
    {
        $this->config()->set('colnect.http.tries', 1);
        Colnect::fake([MockResponse::make(str_repeat('x', 500), 500)]);

        $result = $this->runCommand('colnect:usage');

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(str_repeat('x', 197).'...', $result['output']);
        self::assertStringNotContainsString(str_repeat('x', 198), $result['output']);
    }

    public function test_it_reports_an_exhausted_rate_limit(): void
    {
        Colnect::fake([MockResponse::make([])]);
        Colnect::rateLimiter()->backOff(3600);

        $this->command('colnect:usage')
            ->expectsOutputToContain('Colnect API rate limit "retry_after" is exhausted')
            ->assertFailed();
    }

    public function test_it_reports_an_unreachable_api(): void
    {
        Colnect::fake([
            MockResponse::make()->throw(static fn (PendingRequest $pending): FatalRequestException => new FatalRequestException(new RuntimeException('Could not resolve host'), $pending)),
            MockResponse::make()->throw(static fn (PendingRequest $pending): FatalRequestException => new FatalRequestException(new RuntimeException('Could not resolve host'), $pending)),
            MockResponse::make()->throw(static fn (PendingRequest $pending): FatalRequestException => new FatalRequestException(new RuntimeException('Could not resolve host'), $pending)),
        ]);

        $this->command('colnect:usage')
            ->expectsOutputToContain('Could not resolve host')
            ->assertFailed();
    }

    public function test_it_reports_missing_credentials(): void
    {
        $this->config()->set('colnect.app_id', null);

        $this->command('colnect:usage')
            ->expectsOutputToContain('Set COLNECT_APP_ID')
            ->assertFailed();
    }

    private function assertRequested(MockClient $mock, string $path): void
    {
        $mock->assertSent(static fn (mixed $request, Response $response): bool => $request instanceof GetRequestCountRequest
            && str_ends_with($response->getPendingRequest()->getUrl(), $path));
    }
}
