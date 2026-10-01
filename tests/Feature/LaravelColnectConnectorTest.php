<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Slimad\ColnectApi\ColnectConnector;
use Slimad\ColnectApi\Laravel\ColnectConnectorFactory;
use Slimad\ColnectApi\Laravel\ColnectManager;
use Slimad\ColnectApi\Laravel\Config\ConnectorOptions;
use Slimad\ColnectApi\Laravel\Config\HttpOptions;
use Slimad\ColnectApi\Laravel\Config\RateLimitOptions;
use Slimad\ColnectApi\Laravel\Events\RequestThrottled;
use Slimad\ColnectApi\Laravel\Events\TooManyRequestsReceived;
use Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException;
use Slimad\ColnectApi\Laravel\LaravelColnectConnector;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;
use Slimad\ColnectApi\Laravel\Tests\TestCase;
use Slimad\ColnectApi\Requests\General\GetLanguagesRequest;
use Slimad\ColnectApi\Requests\General\TranslatePhrasesRequest;

final class LaravelColnectConnectorTest extends TestCase
{
    public function test_it_is_a_drop_in_core_connector(): void
    {
        $connector = $this->connector();

        self::assertSame($connector, $this->container()->make(ColnectConnector::class));
        self::assertSame('https://api.colnect.net/en/api/'.self::APP_ID, $connector->resolveBaseUrl());
    }

    public function test_requests_are_still_signed_by_the_core_connector(): void
    {
        $mock = new MockClient([MockResponse::make(['en' => 'English'])]);

        $response = $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

        self::assertSame(['en' => 'English'], $response->json());

        $pending = $mock->getLastPendingRequest();
        self::assertInstanceOf(PendingRequest::class, $pending);

        $timestamp = $pending->headers()->get('Capi-Timestamp');
        self::assertIsString($timestamp);
        self::assertSame(
            hash_hmac('sha256', '/en/api/'.self::APP_ID.'/languages>|<'.$timestamp, self::APP_SECRET),
            $pending->headers()->get('Capi-Hash'),
        );
        self::assertSame('Colnect Test Suite (slimad/colnect-api-laravel)', $pending->headers()->get('User-Agent'));
    }

    public function test_it_applies_the_configured_timeouts_and_keeps_http_2(): void
    {
        $this->config()->set('colnect.http.timeout', 12);
        $this->config()->set('colnect.http.connect_timeout', 3);

        $config = $this->connector()->config()->all();

        self::assertSame(12, $config['timeout']);
        self::assertSame(3, $config['connect_timeout']);
        self::assertSame(2.0, $config['version'], 'CAPI rejects HTTP/1.x');
    }

    public function test_it_applies_the_configured_retry_policy(): void
    {
        $this->config()->set('colnect.http.tries', 5);
        $this->config()->set('colnect.http.retry_delay', 250);
        $this->config()->set('colnect.http.exponential_backoff', false);

        $connector = $this->connector();

        self::assertSame(5, $connector->tries);
        self::assertSame(250, $connector->retryInterval);
        self::assertFalse($connector->useExponentialBackoff);
        self::assertFalse($connector->throwOnMaxTries);
    }

    public function test_every_attempt_counts_against_the_rate_limit(): void
    {
        $this->config()->set('colnect.rate_limit.limits', ['per_minute' => 10]);
        $connector = $this->connector()->withMockClient(new MockClient([MockResponse::make([], 500), MockResponse::make([])]));

        $connector->send(new GetLanguagesRequest);

        self::assertSame(2, $connector->rateLimiter()->usage()[0]['used']);
    }

    public function test_a_429_is_retried_after_the_pause_colnect_asked_for(): void
    {
        Event::fake([TooManyRequestsReceived::class, RequestThrottled::class]);

        $mock = new MockClient([
            MockResponse::make(['error' => 'Too many requests'], 429, ['Retry-After' => '7']),
            MockResponse::make(['en' => 'English']),
        ]);

        $response = $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

        self::assertSame(200, $response->status());
        self::assertSame([7], $this->sleeper->slept);
        $mock->assertSentCount(2);
        Event::assertDispatched(TooManyRequestsReceived::class, static fn (TooManyRequestsReceived $event): bool => $event->retryAfter === 7);
        Event::assertDispatched(RequestThrottled::class, static fn (RequestThrottled $event): bool => $event->limit === RateLimiter::RETRY_AFTER && $event->seconds === 7);
    }

    public function test_a_429_without_retry_after_pauses_for_the_configured_fallback(): void
    {
        $this->config()->set('colnect.rate_limit.retry_after', 20);
        $mock = new MockClient([MockResponse::make([], 429), MockResponse::make([])]);

        $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

        self::assertSame([20], $this->sleeper->slept);
    }

    public function test_the_pause_also_holds_back_the_next_request(): void
    {
        $this->config()->set('colnect.http.tries', 1);
        $connector = $this->connector()->withMockClient(new MockClient([
            MockResponse::make([], 429, ['Retry-After' => '5']),
            MockResponse::make([]),
        ]));

        self::assertSame(429, $connector->send(new GetLanguagesRequest)->status());
        self::assertSame([], $this->sleeper->slept);
        self::assertSame(5, $connector->rateLimiter()->cooldown());

        self::assertSame(200, $connector->send(new GetLanguagesRequest)->status());
        self::assertSame([5], $this->sleeper->slept);
    }

    public function test_a_pause_longer_than_max_wait_gives_up_without_sending_again(): void
    {
        $this->config()->set('colnect.rate_limit.max_wait', 10);
        $mock = new MockClient([MockResponse::make([], 429, ['Retry-After' => '3600'])]);

        try {
            $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);
            self::fail('The connector waited an hour.');
        } catch (RateLimitExceededException $exception) {
            self::assertSame(RateLimiter::RETRY_AFTER, $exception->limit);
            self::assertSame(3600, $exception->retryAfter);
        }

        $mock->assertSentCount(1);
        self::assertSame([], $this->sleeper->slept);
    }

    public function test_a_full_local_window_is_waited_out_before_signing(): void
    {
        $this->config()->set('colnect.rate_limit.limits', ['per_minute' => 1]);
        $this->config()->set('colnect.rate_limit.max_wait', 60);
        $mock = new MockClient([MockResponse::make([]), MockResponse::make([])]);
        $connector = $this->connector()->withMockClient($mock);

        $connector->send(new GetLanguagesRequest);
        $connector->send(new GetLanguagesRequest);

        self::assertSame([60], $this->sleeper->slept);
        $mock->assertSentCount(2);
    }

    public function test_nothing_is_sent_when_the_rate_limit_leaves_no_room(): void
    {
        $this->config()->set('colnect.rate_limit.limits', ['per_minute' => 1]);
        $this->config()->set('colnect.rate_limit.max_wait', 0);
        $mock = new MockClient([MockResponse::make([]), MockResponse::make([])]);
        $connector = $this->connector()->withMockClient($mock);
        $connector->send(new GetLanguagesRequest);

        $this->expectException(RateLimitExceededException::class);

        try {
            $connector->send(new GetLanguagesRequest);
        } finally {
            $mock->assertSentCount(1);
        }
    }

    public function test_server_errors_are_retried_for_get_requests(): void
    {
        $mock = new MockClient([
            MockResponse::make([], 503),
            MockResponse::make([], 500),
            MockResponse::make(['en' => 'English']),
        ]);

        $response = $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

        self::assertSame(200, $response->status());
        $mock->assertSentCount(3);
    }

    public function test_the_last_failed_response_is_returned_not_thrown(): void
    {
        $mock = new MockClient([
            MockResponse::make([], 500),
            MockResponse::make([], 502),
            MockResponse::make(['error' => 'down'], 503),
        ]);

        $response = $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

        self::assertSame(503, $response->status());
        $mock->assertSentCount(3);
    }

    public function test_client_errors_are_not_retried(): void
    {
        foreach ([400, 401, 403, 404, 426, 499] as $status) {
            $mock = new MockClient([MockResponse::make([], $status), MockResponse::make([])]);

            $response = $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

            self::assertSame($status, $response->status());
            $mock->assertSentCount(1);
        }
    }

    public function test_server_errors_are_not_retried_for_post_requests(): void
    {
        $mock = new MockClient([MockResponse::make([], 500), MockResponse::make([])]);

        $response = $this->connector()->withMockClient($mock)->send(new TranslatePhrasesRequest(['Stamps']));

        self::assertSame(500, $response->status());
        $mock->assertSentCount(1);
    }

    public function test_a_429_is_retried_for_post_requests_too(): void
    {
        $mock = new MockClient([MockResponse::make([], 429, ['Retry-After' => '1']), MockResponse::make([])]);

        $response = $this->connector()->withMockClient($mock)->send(new TranslatePhrasesRequest(['Stamps']));

        self::assertSame(200, $response->status());
        $mock->assertSentCount(2);
    }

    public function test_connection_errors_are_retried_for_get_requests(): void
    {
        $mock = new MockClient([
            MockResponse::make()->throw(static fn (PendingRequest $pending): FatalRequestException => new FatalRequestException(new RuntimeException('Connection refused'), $pending)),
            MockResponse::make(['en' => 'English']),
        ]);

        $response = $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

        self::assertSame(200, $response->status());
    }

    public function test_connection_errors_are_not_retried_for_post_requests(): void
    {
        $mock = new MockClient([
            MockResponse::make()->throw(static fn (PendingRequest $pending): FatalRequestException => new FatalRequestException(new RuntimeException('Connection reset'), $pending)),
            MockResponse::make([]),
        ]);

        $this->expectException(FatalRequestException::class);
        $this->expectExceptionMessage('Connection reset');

        $this->connector()->withMockClient($mock)->send(new TranslatePhrasesRequest(['Stamps']));
    }

    public function test_a_single_try_hands_back_a_429_without_retrying(): void
    {
        $this->config()->set('colnect.http.tries', 1);
        $mock = new MockClient([MockResponse::make([], 429), MockResponse::make([])]);

        $response = $this->connector()->withMockClient($mock)->send(new GetLanguagesRequest);

        self::assertSame(429, $response->status());
        $mock->assertSentCount(1);
    }

    public function test_async_requests_record_a_429_too(): void
    {
        $connector = $this->connector()->withMockClient(new MockClient([MockResponse::make([], 429, ['Retry-After' => '9'])]));

        try {
            $connector->sendAsync(new GetLanguagesRequest)->wait();
            self::fail('A failed asynchronous request has to stay rejected.');
        } catch (TooManyRequestsException $exception) {
            self::assertSame(429, $exception->getResponse()->status());
        }

        self::assertSame(9, $connector->rateLimiter()->cooldown());
    }

    public function test_async_requests_leave_other_failures_alone(): void
    {
        $connector = $this->connector()->withMockClient(new MockClient([MockResponse::make([], 500, ['Retry-After' => '9'])]));

        try {
            $connector->sendAsync(new GetLanguagesRequest)->wait();
            self::fail('A failed asynchronous request has to stay rejected.');
        } catch (RequestException $exception) {
            self::assertSame(500, $exception->getResponse()->status());
        }

        self::assertSame(0, $connector->rateLimiter()->cooldown());
    }

    public function test_async_connection_errors_stay_rejected(): void
    {
        $connector = $this->connector()->withMockClient(new MockClient([
            MockResponse::make()->throw(static fn (PendingRequest $pending): FatalRequestException => new FatalRequestException(new RuntimeException('Connection refused'), $pending)),
        ]));

        $this->expectException(FatalRequestException::class);

        $connector->sendAsync(new GetLanguagesRequest)->wait();
    }

    public function test_successful_async_requests_resolve_to_the_response(): void
    {
        $connector = $this->connector()->withMockClient(new MockClient([MockResponse::make(['en' => 'English'])]));

        $response = $connector->sendAsync(new GetLanguagesRequest)->wait();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(['en' => 'English'], $response->json());
    }

    public function test_request_pools_record_a_429_too(): void
    {
        $connector = $this->connector()->withMockClient(new MockClient([MockResponse::make([], 429, ['Retry-After' => '4'])]));
        $rejected = [];

        $connector->pool([new GetLanguagesRequest], 1, null, static function (mixed $reason) use (&$rejected): void {
            $rejected[] = $reason;
        })->send()->wait();

        self::assertCount(1, $rejected);
        self::assertSame(4, $connector->rateLimiter()->cooldown());
    }

    public function test_a_disabled_rate_limiter_lets_everything_through(): void
    {
        $this->config()->set('colnect.rate_limit.enabled', false);
        $this->config()->set('colnect.rate_limit.limits', ['per_second' => 1]);
        $mock = new MockClient([
            MockResponse::make([], 429, ['Retry-After' => '30']),
            MockResponse::make([]),
            MockResponse::make([]),
        ]);
        $connector = $this->connector()->withMockClient($mock);

        $connector->send(new GetLanguagesRequest);
        $connector->send(new GetLanguagesRequest);

        $mock->assertSentCount(3);
        self::assertSame([], $this->sleeper->slept);
    }

    public function test_the_rate_limit_is_kept_in_the_configured_cache_store(): void
    {
        $this->config()->set('cache.stores.colnect', ['driver' => 'array']);
        $this->config()->set('colnect.rate_limit.cache_store', 'colnect');
        $this->config()->set('colnect.rate_limit.limits', ['per_minute' => 10]);

        $this->connector()->withMockClient(new MockClient([MockResponse::make([])]))->send(new GetLanguagesRequest);

        self::assertSame(1, $this->container()->make('cache')->store('colnect')->get('colnect-api:per_minute'));
        self::assertNull($this->container()->make('cache')->store('array')->get('colnect-api:per_minute'));
    }

    public function test_time_moves_on_while_waiting(): void
    {
        $this->config()->set('colnect.rate_limit.limits', ['per_minute' => 1]);
        $this->config()->set('colnect.rate_limit.max_wait', 60);
        $connector = $this->connector()->withMockClient(new MockClient([MockResponse::make([]), MockResponse::make([])]));
        $started = Carbon::now()->getTimestamp();

        $connector->send(new GetLanguagesRequest);
        $connector->send(new GetLanguagesRequest);

        self::assertSame(60, Carbon::now()->getTimestamp() - $started);
    }

    private function connector(): LaravelColnectConnector
    {
        // A fresh container binding per call picks up config changed in the test.
        foreach ([
            ConnectorOptions::class,
            HttpOptions::class,
            RateLimitOptions::class,
            RateLimiter::class,
            ColnectConnectorFactory::class,
            ColnectManager::class,
        ] as $abstract) {
            $this->container()->forgetInstance($abstract);
        }

        return $this->container()->make(LaravelColnectConnector::class);
    }
}
