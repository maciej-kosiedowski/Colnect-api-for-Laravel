# AGENTS.md

Guidance for AI coding agents (Claude Code, Codex, Copilot and others) and for humans who like
checklists. Read it before changing anything in this repository.

## What this is

`slimad/colnect-api-laravel` - an open-source Laravel integration for the Colnect API (CAPI). It
wraps the framework-agnostic SDK [`slimad/colnect-api`](https://github.com/maciej-kosiedowski/Colnect-api)
(Saloon 3 based) and adds only Laravel glue. Request classes, signing and input validation live in
the core SDK; changes to those belong there, not here.

## Layout

| Path | Contents |
| ---- | -------- |
| `src/ColnectServiceProvider.php` | container bindings, config merging and publishing, commands, `about` section |
| `src/ColnectManager.php`, `src/Facades/Colnect.php` | application-facing entry point and its facade |
| `src/ColnectConnectorFactory.php` | builds connectors from configuration |
| `src/LaravelColnectConnector.php` | core connector + timeouts, retries, rate limiting, 429 handling |
| `src/RateLimiting/` | cache-backed `RateLimiter`, `Limit`, `RetryAfter` parser |
| `src/Config/` | typed, immutable option objects read from `config/colnect.php` |
| `src/Console/` | `colnect:status`, `colnect:usage` |
| `config/colnect.php` | the published configuration; every key has an env variable |
| `tests/Unit`, `tests/Feature` | PHPUnit tests on Orchestra Testbench |

## Commands

```bash
composer install
composer cs:fix      # Laravel Pint
composer phpstan     # Larastan, maximum level
composer test        # PHPUnit
composer infection   # mutation testing, needs pcov or xdebug
composer ci          # everything above, as CI runs it
```

Run `composer ci` - or at least `cs`, `phpstan` and `test` - before you consider a change done.

## Quality bar (enforced by CI)

* 100% line coverage (`bin/check-coverage.php`) and a 100% mutation score (`infection.json5`).
  Kill a surviving mutant with a sharper test, or simplify the code until the mutant is gone. Never
  lower a threshold, never add `@codeCoverageIgnore`, `@infection-ignore-all` or `@phpstan-ignore`.
* Larastan at `level: max` with no baseline.
* `declare(strict_types=1)` everywhere; classes are `final` (Pint enforces it), value objects
  `final readonly`.
* Configuration values may arrive as strings from `env()`: read them through `Config\ConfigReader`,
  never with bare casts.

## Compatibility

Supported: Laravel 9.52 - 13 on PHP 8.2 - 8.5 (see the matrix in `.github/workflows/ci.yml`).

* Do not use framework APIs newer than Laravel 9.52 (for example `Illuminate\Support\Sleep`,
  `Config::integer()`, `Number`). Guard them with `class_exists()`/`method_exists()` if unavoidable.
* Tests run on PHPUnit 9.6 to 13: no data providers (annotations and attributes are not portable
  across that range), no assertions newer than PHPUnit 9.6.
* Do not use `DATE_RFC7231` or other constants deprecated in PHP 8.5.
* The core SDK pins Saloon `^3.0`; keep everything working on Saloon 3.0.0.

## Conventions

* Documentation, code comments, commit messages and changelog entries are written in English.
* New configuration keys go into `config/colnect.php` **and** the README configuration table, with an
  environment variable.
* Every user-visible change gets an entry under `## [Unreleased]` in `CHANGELOG.md`.
* Never commit credentials, `.env` files or real CAPI keys. Tests use `test-app-id` /
  `test-app-secret`; the pre-commit hooks (`detect-secrets`, `gitleaks`) must stay green.
* Never print or log the application secret - `colnect:status` only says whether it is set.

## Commits and pull requests

* Write commit messages in the imperative mood ("Add ...", "Fix ..."), with a short summary line.
* **No AI attribution.** Do not add `Co-Authored-By:` trailers naming an AI assistant, "Generated
  with ..." footers, or similar lines to commits, pull requests or code. The repository's
  `.claude/settings.json` turns Claude Code's attribution off; other agents have to honour this rule
  by hand.
* Branch off `master`; keep pull requests focused and fill in the pull request template.
