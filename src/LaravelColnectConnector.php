<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Slimad\ColnectApi\ColnectConnector;
use Slimad\ColnectApi\Exceptions\InvalidArgumentException;
use Slimad\ColnectApi\Laravel\Config\HttpOptions;
use Slimad\ColnectApi\Laravel\RateLimiting\RateLimiter;
use Slimad\ColnectApi\Laravel\RateLimiting\RetryAfter;

/**
 * The core connector, plus what a long-running Laravel application needs:
 * timeouts, retries that honour Retry-After, and a rate limiter shared by every
 * process.
 *
 * It is a drop-in {@see ColnectConnector}: type-hint the core class and the
 * container hands you this one.
 */
final class LaravelColnectConnector extends ColnectConnector
{
    public const TOO_MANY_REQUESTS = 429;

    /**
     * @throws InvalidArgumentException when the credentials, language or user agent are malformed
     */
    public function __construct(
        string $appId,
        string $appSecret,
        string $language,
        string $userAgent,
        private readonly HttpOptions $http,
        private readonly RateLimiter $rateLimiter,
    ) {
        parent::__construct($appId, $appSecret, $language, $userAgent);

        $this->tries = $http->tries;
        $this->retryInterval = $http->retryDelay;
        $this->useExponentialBackoff = $http->exponentialBackoff;

        // Retrying must not change what send() returns: once the attempts are
        // spent, a failed response is handed back just as it would be without
        // retries, rather than thrown.
        $this->throwOnMaxTries = false;
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Runs once per attempt, right before the request is signed.
     *
     * Waiting for the rate limiter has to happen first: CAPI signs the
     * timestamp, and a signature computed before a long wait would be stale by
     * the time the request leaves.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        $this->rateLimiter->acquire();

        parent::boot($pendingRequest);

        $pendingRequest->middleware()->onResponse(function (Response $response): Response {
            $this->recordTooManyRequests($response);

            return $response;
        }, 'colnectRateLimit');
    }

    /**
     * Saloon rejects an asynchronous request that failed before the response
     * middleware runs, so a 429 is picked up from the rejection instead. This
     * covers request pools as well, which send through here.
     */
    public function sendAsync(Request $request, ?MockClient $mockClient = null): PromiseInterface
    {
        return parent::sendAsync($request, $mockClient)->then(null, function (mixed $reason): PromiseInterface {
            if ($reason instanceof RequestException) {
                $this->recordTooManyRequests($reason->getResponse());
            }

            return Create::rejectionFor($reason);
        });
    }

    /**
     * A 429 is always safe to retry: Colnect refused the request without acting
     * on it. Server and connection errors are only retried for GET requests -
     * a POST such as an image search is billed and may already have run.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        $status = $exception instanceof RequestException ? $exception->getResponse()->status() : null;

        if ($status === self::TOO_MANY_REQUESTS) {
            return true;
        }

        if ($request->getMethod() !== Method::GET) {
            return false;
        }

        return $status === null || $status >= 500;
    }

    private function recordTooManyRequests(Response $response): void
    {
        if ($response->status() !== self::TOO_MANY_REQUESTS) {
            return;
        }

        $this->rateLimiter->backOff(RetryAfter::seconds(
            $response->header('Retry-After'),
            $this->rateLimiter->options()->retryAfter,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            ...parent::defaultConfig(),
            'timeout' => $this->http->timeout,
            'connect_timeout' => $this->http->connectTimeout,
        ];
    }
}
