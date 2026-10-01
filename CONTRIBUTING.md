# Contributing

Thanks for taking the time to contribute!

## Getting started

```bash
git clone https://github.com/maciej-kosiedowski/Colnect-api-for-Laravel.git
cd Colnect-api-for-Laravel
composer install
```

A coverage driver (`pcov` or `xdebug`) is required for the coverage and mutation testing steps; the
plain test suite runs without one. The test suite never talks to Colnect and needs no credentials.

This repo also ships [pre-commit](https://pre-commit.com) hooks (`detect-secrets` and `gitleaks`)
that scan staged changes for accidentally committed credentials. Run `pip install pre-commit &&
pre-commit install` once so they run automatically on every commit.

### Working against a local copy of the core SDK

The package depends on [`slimad/colnect-api`](https://github.com/maciej-kosiedowski/Colnect-api). To
test against a checkout of it rather than the released version:

```bash
composer config repositories.slimad-colnect-api \
    '{"type":"path","url":"../Colnect-api","options":{"versions":{"slimad/colnect-api":"1.0.0"}}}'
composer update slimad/colnect-api
```

Remove the `repositories` entry from `composer.json` before committing — it must not ship in a
published package.

## Quality gate

Every pull request has to pass the same gate that CI runs:

```bash
composer cs         # Laravel Pint
composer phpstan    # Larastan, maximum level
composer test       # PHPUnit against Testbench
composer infection  # mutation testing
```

or simply:

```bash
composer ci
```

`composer cs:fix` rewrites the files that violate the coding standard.

To run a single test file or a single test:

```bash
vendor/bin/phpunit tests/Unit/RateLimiting/RateLimiterTest.php
vendor/bin/phpunit --filter test_a_429_is_retried_after_the_pause_colnect_asked_for
```

## Non-negotiables

* **100% line coverage**, enforced by `bin/check-coverage.php` in CI. New behaviour needs tests.
* **100% mutation score** (`infection.json5`). A surviving mutant means a test is not specific
  enough — tighten the assertion, or simplify the code until the mutant disappears. Do not lower the
  bar.
* **Larastan at the maximum level**, no baseline, no `@phpstan-ignore`.
* **No new runtime dependencies** beyond `illuminate/*`, `saloonphp/saloon`, `guzzlehttp/promises` and `slimad/colnect-api`
  without discussing it first.
* **API changes belong in the core SDK.** This repository only holds Laravel glue: configuration,
  container bindings, rate limiting, retries, commands and test helpers.
* **Never commit credentials**, not even test ones that look real. Tests use `test-app-id` and
  `test-app-secret`.

## Supported versions

Pull requests have to work on every supported combination:

| Laravel | PHP |
| ------- | --- |
| 9.52+ | 8.2 |
| 10 | 8.2, 8.3 |
| 11 | 8.2, 8.3, 8.4 |
| 12 | 8.2, 8.3, 8.4, 8.5 |
| 13 | 8.3, 8.4, 8.5 |

The floor is set by the core SDK (PHP 8.2). Avoid framework APIs that only exist in newer releases
(`Illuminate\Support\Sleep`, the typed `Config::integer()` getters, ...); when you cannot, guard them.
The test suite runs on PHPUnit 9 to 13, so stick to assertions that exist in all of them and do not
use data providers (their annotation and attribute forms are not portable across that range).

## Pull requests

1. Branch off `master`.
2. Keep the change focused.
3. Add a `CHANGELOG.md` entry under `## [Unreleased]`.
4. Document new configuration keys in `config/colnect.php` **and** in the README table.
5. Make sure `composer ci` is green locally before pushing.

## Reporting bugs

Open an issue with the package version, the Laravel and PHP versions, the cache store used for rate
limiting and a minimal reproducer. Never include your application secret. Security problems go
through the process described in [SECURITY.md](SECURITY.md) instead.
