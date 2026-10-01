# Colnect API for Laravel

[![CI](https://github.com/maciej-kosiedowski/Colnect-api-for-Laravel/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/maciej-kosiedowski/Colnect-api-for-Laravel/actions/workflows/ci.yml)
[![Security](https://github.com/maciej-kosiedowski/Colnect-api-for-Laravel/actions/workflows/security.yml/badge.svg?branch=master)](https://github.com/maciej-kosiedowski/Colnect-api-for-Laravel/actions/workflows/security.yml)
[![Latest stable version](https://img.shields.io/packagist/v/slimad/colnect-api-laravel.svg)](https://packagist.org/packages/slimad/colnect-api-laravel)
[![PHP version](https://img.shields.io/packagist/dependency-v/slimad/colnect-api-laravel/php.svg)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Laravel integration for the [Colnect](https://colnect.com) API (CAPI) - the catalogue of stamps,
coins, banknotes, phone cards and 40-odd other collectibles.

It wraps the framework-agnostic [`slimad/colnect-api`](https://github.com/maciej-kosiedowski/Colnect-api)
SDK and adds everything Laravel-shaped: configuration, container bindings, a facade, a rate limiter
shared by every web request and queue worker, retries that honour `Retry-After`, Artisan commands
and a one-line test fake.

```php
use Slimad\ColnectApi\Enums\CategoryType;
use Slimad\ColnectApi\Laravel\Facades\Colnect;
use Slimad\ColnectApi\Requests\Category\GetCountriesRequest;

$countries = Colnect::send(new GetCountriesRequest(CategoryType::Stamps))->json();
```

## How it works

```
 your code ──► Colnect facade / injected ColnectConnector
                         │
                         ▼
            ┌─────────── rate limiter ───────────┐   counters live in your cache store,
            │ per second / minute / hour / day   │   so every process shares one quota
            │ + any pause Colnect asked for (429)│
            └────────────────┬───────────────────┘
                             │  waits up to max_wait, otherwise throws
                             ▼
            HMAC signing (Capi-Timestamp, Capi-Hash) ──► https://api.colnect.net (HTTP/2)
                             │
              429 ◄──────────┘  Retry-After is recorded for everybody, then retried
```

Every request counts against the configured limits **before** it is signed, so a request that had to
wait still carries a fresh timestamp. When Colnect answers `429 Too Many Requests`, the pause it asks
for is stored in the cache as well: every other worker holds back too, instead of finding out on its
own.

## Requirements

| Laravel | PHP |
| ------- | --- |
| 9.52+ | 8.2 |
| 10.x | 8.2, 8.3 |
| 11.x | 8.2, 8.3, 8.4 |
| 12.x | 8.2, 8.3, 8.4, 8.5 |
| 13.x | 8.3, 8.4, 8.5 |

PHP 8.2 is the floor set by `slimad/colnect-api`. CAPI is served over HTTP/2 only, so the cURL build
behind Guzzle has to support it (`curl --version` lists `HTTP2`).

You need an application ID and secret from Colnect: [apply for a CAPI key](https://colnect.com/en/capi/apply_for_key).

## Installation

```bash
composer require slimad/colnect-api-laravel
```

The service provider and the `Colnect` facade are registered automatically.

Add your credentials to `.env`:

```env
COLNECT_APP_ID=your-app-id
COLNECT_APP_SECRET=your-app-secret
COLNECT_USER_AGENT="MyCollection/1.0 (+https://example.com)"
```

Check that everything is wired up:

```bash
php artisan colnect:status   # what the package resolved, and how much of the rate limit is used
php artisan colnect:usage    # asks Colnect how many requests you made per day
```

Publishing the configuration file is optional - every setting has an environment variable:

```bash
php artisan vendor:publish --tag=colnect-config
```

## Usage

### Through the facade

```php
use Slimad\ColnectApi\Enums\CategoryType;
use Slimad\ColnectApi\Laravel\Facades\Colnect;
use Slimad\ColnectApi\Requests\Category\GetItemsListRequest;
use Slimad\ColnectApi\Support\Filters;

$items = Colnect::send(new GetItemsListRequest(
    CategoryType::Stamps,
    Filters::make()->country(5)->year(1980),
))->json();
```

Every request class of [`slimad/colnect-api`](https://github.com/maciej-kosiedowski/Colnect-api#available-requests)
works - categories, items, pictures, search, image search, marketplace prices and collector ratings.

### Through dependency injection

Type-hint the core connector; the container hands you the configured, rate-limited one:

```php
use Slimad\ColnectApi\ColnectConnector;
use Slimad\ColnectApi\Requests\General\GetCategoriesRequest;

final class SyncCategories
{
    public function __construct(private ColnectConnector $colnect) {}

    public function __invoke(): array
    {
        return $this->colnect->send(new GetCategoriesRequest)->json();
    }
}
```

### Other languages

`colnect.language` sets the default. Any other language is one call away and shares the same rate
limit - the quota belongs to your application, not to a language:

```php
Colnect::connector('pl')->send(new GetCountriesRequest(CategoryType::Coins));
```

### Asynchronous requests and pools

Saloon's asynchronous API and request pools go through the same rate limiter:

```php
$promise = Colnect::sendAsync(new GetCategoriesRequest);

Colnect::connector()->pool($requests, concurrency: 5)->send()->wait();
```

## Configuration

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `app_id` | `COLNECT_APP_ID` | — | Your CAPI application ID. Part of every request URL. |
| `app_secret` | `COLNECT_APP_SECRET` | — | Your CAPI secret. Only ever used to sign requests, never sent or printed. |
| `language` | `COLNECT_LANGUAGE` | `en` | Language responses are translated into: `en`, `pl`, `pt_BR`... |
| `user_agent` | `COLNECT_USER_AGENT` | `{app.name} (slimad/colnect-api-laravel)` | Identifies your application to Colnect; 16+ characters. |
| `http.timeout` | `COLNECT_HTTP_TIMEOUT` | `30` | Request timeout in seconds. |
| `http.connect_timeout` | `COLNECT_HTTP_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds. |
| `http.tries` | `COLNECT_HTTP_TRIES` | `3` | Attempts per request (see [Retries](#retries)). |
| `http.retry_delay` | `COLNECT_HTTP_RETRY_DELAY` | `500` | Milliseconds before the second attempt. |
| `http.exponential_backoff` | `COLNECT_HTTP_EXPONENTIAL_BACKOFF` | `true` | Double the delay after every attempt. |
| `rate_limit.enabled` | `COLNECT_RATE_LIMIT_ENABLED` | `true` | Master switch for the limits **and** for honouring 429 pauses. |
| `rate_limit.cache_store` | `COLNECT_RATE_LIMIT_CACHE_STORE` | default store | Where counters live. Must be shared by every process. |
| `rate_limit.prefix` | `COLNECT_RATE_LIMIT_PREFIX` | `colnect-api` | Cache key prefix. |
| `rate_limit.limits.per_second` | `COLNECT_RATE_LIMIT_PER_SECOND` | `2` | Requests per second; empty or `0` for no limit. |
| `rate_limit.limits.per_minute` | `COLNECT_RATE_LIMIT_PER_MINUTE` | `60` | Requests per minute. |
| `rate_limit.limits.per_hour` | `COLNECT_RATE_LIMIT_PER_HOUR` | — | Requests per hour. |
| `rate_limit.limits.per_day` | `COLNECT_RATE_LIMIT_PER_DAY` | — | Requests per day. |
| `rate_limit.max_wait` | `COLNECT_RATE_LIMIT_MAX_WAIT` | `30` | Longest a request may wait for a free slot, in seconds; `0` never waits. |
| `rate_limit.retry_after` | `COLNECT_RATE_LIMIT_RETRY_AFTER` | `60` | Pause after a 429 that carries no `Retry-After` header. |

## Rate limiting

Colnect grants every application a request quota. The defaults - 2 requests per second, 60 per
minute - are a polite starting point, not Colnect's numbers: **set the limits your CAPI agreement
specifies.**

```env
COLNECT_RATE_LIMIT_PER_SECOND=5
COLNECT_RATE_LIMIT_PER_MINUTE=120
COLNECT_RATE_LIMIT_PER_DAY=10000
```

### One quota for the whole application

Counters live in the cache, so the limits hold across every web request, queue worker, Horizon
process and Artisan command - as long as they share the store. Use `redis`, `memcached`, `database`
or `dynamodb` in production; the `array` store counts per process only, and the `file` store only on a
single server.

```env
COLNECT_RATE_LIMIT_CACHE_STORE=redis
```

Each window starts with its first request and lasts its full length (a fixed window, like Laravel's
own `RateLimiter`). A daily window therefore runs for 24 hours from the first request, not from
midnight.

### Waiting, and when not to

When a window is full, the request **waits** for the next free slot, up to `max_wait` seconds in
total. When the wait would be longer, it throws
`Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException` right away, **without sending
anything** and without waiting part of the way first.

In a queued job, give the slot back to the queue instead of blocking a worker:

```php
use Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException;

public function handle(ColnectConnector $colnect): void
{
    try {
        $response = $colnect->send(new GetItemRequest(CategoryType::Coins, $this->itemId));
    } catch (RateLimitExceededException $exception) {
        $this->release($exception->retryAfter);

        return;
    }

    // ...
}
```

In a web request, a long wait is rarely what you want: lower `max_wait` (or set it to `0`) and
serve something cached instead.

### When Colnect says "slow down"

A `429 Too Many Requests` response pauses **every** process for as long as its `Retry-After` header
says (delay-seconds or an HTTP date, capped at one day), or for `retry_after` seconds when it says
nothing. The request is then retried, provided it has tries left and the pause fits within
`max_wait`.

### Events

| Event | When |
| --- | --- |
| `Slimad\ColnectApi\Laravel\Events\RequestThrottled` | a request is about to wait (`$event->limit`, `$event->seconds`) |
| `Slimad\ColnectApi\Laravel\Events\TooManyRequestsReceived` | Colnect answered 429 (`$event->retryAfter`) |

```php
Event::listen(TooManyRequestsReceived::class, function (TooManyRequestsReceived $event): void {
    Log::warning('Colnect asked us to slow down', ['seconds' => $event->retryAfter]);
});
```

### Inspecting the limiter

```php
Colnect::rateLimiter()->usage();     // per window: used, remaining, resets_in
Colnect::rateLimiter()->cooldown();  // seconds left of a 429 pause
Colnect::rateLimiter()->clear();     // forget every counter and pause
```

## Retries

`http.tries` attempts are made per request:

* `429` responses are retried for every request - Colnect refused them without acting on them;
* server errors (`5xx`) and connection failures are retried for `GET` requests only. A `POST`, such
  as a billed image search, may already have been processed;
* other client errors (`4xx`) are never retried.

Every attempt counts against the rate limit. Once the attempts are spent the last response is
returned - exactly what `send()` returns without retries - so check `$response->failed()` or call
`$response->throw()` as usual. A connection failure that persists throws Saloon's
`FatalRequestException`.

## Artisan commands

| Command | What it does |
| --- | --- |
| `colnect:status` | Prints the resolved configuration (never the secret) and the current rate limit usage. Fails when the package is not configured. |
| `colnect:usage {--days=7}` | Asks Colnect how many requests the application made per day, up to 200 days back. |

`php artisan about` shows whether the package is configured, its language and whether rate limiting
is on.

## Testing your application

`Colnect::fake()` answers every request from a Saloon
[`MockClient`](https://docs.saloon.dev/the-basics/testing) - nothing reaches the network, and no
real credentials are needed in CI:

```php
use Saloon\Http\Faking\MockResponse;
use Slimad\ColnectApi\Laravel\Facades\Colnect;
use Slimad\ColnectApi\Requests\General\GetCategoriesRequest;

public function test_it_imports_categories(): void
{
    $mock = Colnect::fake([
        GetCategoriesRequest::class => MockResponse::make(['stamps', 'coins']),
    ]);

    $this->artisan('app:import-categories')->assertSuccessful();

    $mock->assertSent(GetCategoriesRequest::class);
    $mock->assertSentCount(1);
}
```

The fake reaches connectors that were already injected into your classes as well as those built
afterwards. Requests are still signed and still go through the rate limiter, so you can test how your
code handles a `RateLimitExceededException` or a `429`.

The limiter sleeps through `Slimad\ColnectApi\Laravel\Contracts\Sleeper`. Bind your own
implementation to make waiting instant in tests:

```php
$this->app->instance(Sleeper::class, new class implements Sleeper {
    public function sleep(int $seconds): void {}
});
```

Or switch the limiter off for a test entirely:

```php
config()->set('colnect.rate_limit.enabled', false);
```

## Troubleshooting

**`Colnect API is not configured: "colnect.app_id" is empty.`** — set `COLNECT_APP_ID` and
`COLNECT_APP_SECRET`. `php artisan colnect:status` shows what the package resolved; remember
`php artisan config:clear` if you cache your configuration.

**`User agent must be at least 16 characters long`** — Colnect wants a user agent that identifies
your application. Set `COLNECT_USER_AGENT`, or give `APP_NAME` a few more characters.

**`426 Upgrade Required`** — CAPI only speaks HTTP/2. Make sure the cURL extension PHP uses supports
it.

**`RateLimitExceededException` in production although traffic is low** — check that every process
uses the same `COLNECT_RATE_LIMIT_CACHE_STORE`, and look for a 429 pause in `colnect:status`.

**Requests are slow** — they may be waiting for a free slot. Listen to `RequestThrottled` to see how
often, and raise the limits if your agreement allows it.

## Security

* The secret never leaves your server: it only signs requests, and `colnect:status` reports whether
  it is set, never its value.
* The package depends on Saloon 3 through `slimad/colnect-api`. Three advisories filed against
  Saloon 3 (CVE-2026-33182, CVE-2026-33183, CVE-2026-33942) concern features this integration does
  not use - absolute request endpoints, fixtures and the OAuth access token authenticator. They are
  ignored in this repository's `composer audit` with that reasoning, and will go away once the core
  SDK supports Saloon 4.

Please report vulnerabilities privately, as described in [SECURITY.md](SECURITY.md).

## Quality gates

* PHP 8.2 - 8.5 × Laravel 9 - 13, plus a lowest-dependency run
* 100% line coverage, enforced in CI
* 100% mutation score with [Infection](https://infection.github.io/)
* Larastan at the maximum level, no baseline
* Laravel Pint for the coding standard
* `composer audit`, Dependabot, `gitleaks` and `detect-secrets`

```bash
composer ci          # cs + phpstan + test + infection
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Colnect's terms

Using CAPI is subject to Colnect's terms of service and to your CAPI agreement, including any
attribution it requires. This package is not affiliated with or endorsed by Colnect.

## License

MIT — see [LICENSE](LICENSE).
