# Changelog

## 2.2.0 - 2026-09-28

- Add SingleAttemptDataGetterInterface/getEnvelopeOutcomesOnce for caller-owned recovery, with one attempt per queued URL and no retry cooldown.
- Preserve existing retry-enabled methods and retain every keyed outcome, status and Retry-After.
- Verify queue cleanup, concurrency restoration and no hidden retries with the default retry policy.

## 2.1.0 — 2026-09-27

- Add SettledDataGetterInterface and per-request RequestOutcome/RequestFailure objects for multiget with independent successes and failures.
- Preserve successful envelopes and input queue order after terminal HTTP/provider errors or invalid JSON; retry only pending transient failures and record attempts.
- Allow a per-call concurrency override with queue cleanup and restoration; verify actual outstanding Guzzle HTTP promises stay within concurrency.
- Preserve the existing DataGetterInterface and fail-fast methods. Add multiget examples and partial-failure regressions.

- Read complete GET response streams, including short reads, without a package-defined response-size limit; sanitize stream-read failures.
- Preserve the URL's explicit query over injected Guzzle defaults and disable inherited transport debug output.
- Redact URL and response-body arguments in queue, batch, transport and decoder exception traces while retaining authenticated HTTPS GET support.
- Preserve access_token in authenticated HTTPS GET requests; require HTTPS for token-bearing requests, including loopback URLs. Reject ambiguous control characters in URLs and parameter names.
- Declare the directly used guzzlehttp/psr7 dependency; add local cURL/StreamHandler full-body and gzip regressions above 8 MiB, and PHP 8.5 CI.

## 2.0.0 — 2026-09-27

Major migration from the legacy PHP 5.4 code; requires PHP 8.5 and ext-curl.

- Replace the legacy Curl dependency with Guzzle's concurrent GET transport and injectable contracts.
- Add bounded concurrency, timeouts, TLS verification, process-local pacing and retry policies.
- Preserve WG envelopes when requested and reject malformed responses with sanitized exceptions.
- Retry only failed requests; consume the queue on success or failure and preserve request order.
- Remove implicit WgApi/helper/logging dependencies; support PSR-3 logger injection.
- Add runnable examples, MIT license, PHPUnit, PHPStan level 6 and PSR-12 checks.

Validation: 29 tests / 85 assertions, including localhost HTTP transport checks; live public requests passed in EU/NA/ASIA. This package supports GET; authentication POST belongs to WgAuth.
