# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

Initial implementation of the Laravel integration for `slimad/colnect-api`:

* `ColnectServiceProvider` with auto-discovery, a publishable `config/colnect.php` holding the
  application ID, secret, language, user agent, HTTP and rate limit settings, every one of them
  backed by an environment variable.
* `ColnectManager` and the `Colnect` facade - `send()`, `sendAsync()`, `connector(?string $language)`,
  `rateLimiter()` and `fake()`. Type-hinting the core `ColnectConnector` resolves the configured one.
* `LaravelColnectConnector` - the core connector plus timeouts, retries and rate limiting. `429`
  responses are retried for every request, server and connection errors for `GET` requests only, and
  a failed response is still returned rather than thrown once the attempts are spent.
* A cache-backed `RateLimiter` shared by every process: per second, minute, hour and day windows, a
  `Retry-After` aware pause after HTTP 429 (also for asynchronous requests and pools), waiting up to a
  configurable `max_wait` and throwing `RateLimitExceededException` - without sending anything -
  beyond it.
* Events: `RequestThrottled` and `TooManyRequestsReceived`.
* A swappable `Sleeper` contract, so waiting can be made instant or observable in tests.
* `Colnect::fake()` - answers from a Saloon `MockClient`, reaches connectors that were already
  injected, and needs no real credentials.
* Artisan commands: `colnect:status` and `colnect:usage`, plus a section in `php artisan about`.
* Support for Laravel 9.52 to 13 on PHP 8.2 to 8.5.
* Continuous integration: coding standards, Larastan at the maximum level, the full Laravel × PHP
  matrix plus a lowest-dependency run, a 100% line coverage gate, a 100% mutation score,
  `composer audit`, secret scanning and Dependabot.

[Unreleased]: https://github.com/maciej-kosiedowski/Colnect-api-for-Laravel/compare/master...HEAD
