# Security Policy

## Supported versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

Only the latest minor release of the current major version receives security fixes.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report vulnerabilities privately through GitHub Security Advisories:

<https://github.com/maciej-kosiedowski/Colnect-api-for-Laravel/security/advisories/new>

Please include:

* a description of the problem and its impact,
* the affected version(s) of this package, of Laravel and of PHP,
* steps to reproduce, ideally a minimal reproducer.

Never include a real CAPI application secret in a report. If you believe yours leaked, revoke it in
your Colnect account and request a new one.

You will get an acknowledgement within 7 days. Once a fix is ready a patch release is published and
the advisory is disclosed together with a credit, unless you prefer to stay anonymous.

If the problem is in how requests are built or signed rather than in the Laravel integration, report
it against [slimad/colnect-api](https://github.com/maciej-kosiedowski/Colnect-api/security/advisories/new)
instead.

## Scope

Things that are in scope:

* anything that would leak the application secret - into logs, exceptions, command output, events
  or requests;
* anything that would let a request reach a host other than `api.colnect.net`;
* anything that would let one tenant's input bypass or poison the shared rate limiter.

The application ID is not a secret: it is part of every request URL.

## Known advisories in dependencies

Saloon 3, which the core SDK depends on, has three published advisories (CVE-2026-33182,
CVE-2026-33183, CVE-2026-33942) that are fixed in Saloon 4 only. None of them concerns a feature this
package or the core SDK uses; the reasoning for each is recorded under `config.audit.ignore` in
`composer.json`.
