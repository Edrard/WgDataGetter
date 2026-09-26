# Changelog

## 2.0.0 — 2026-09-27

Major migration from the legacy PHP 5.4 code; requires PHP 8.5 and ext-curl.

- Replace the legacy Curl dependency with Guzzle's concurrent GET transport and injectable contracts.
- Add bounded concurrency, timeouts, TLS verification, process-local pacing and retry policies.
- Preserve WG envelopes when requested and reject malformed responses with sanitized exceptions.
- Retry only failed requests; consume the queue on success or failure and preserve request order.
- Remove implicit WgApi/helper/logging dependencies; support PSR-3 logger injection.
- Add runnable examples, MIT license, PHPUnit, PHPStan level 6 and PSR-12 checks.

Validation: 29 tests / 85 assertions, including localhost HTTP transport checks; live public requests passed in EU/NA/ASIA. This package supports GET; authentication POST belongs to WgAuth.
