# WgDataGetter — PHP 8.5

Concurrent GET fetcher with WG response validation and bounded retries. Requires PHP 8.5, ext-curl and Composer 2.

```sh
composer install
composer test
composer analyse
composer format:check
```

```php
use edrard\WgGetter\WgDataGetter;

$getter = new WgDataGetter(multi: 5);
$getter->setUrls($urls);
$data = $getter->getData(); // request key => WG data, including null
```

## Dependencies and extension points

Guzzle 7 (MIT) provides an asynchronous request Pool. BatchTransportInterface supports alternative transports and test doubles. RateLimiterInterface supports application-wide quota coordination. An injected PSR-3 logger (MIT) receives diagnostics; the default is NullLogger.

```php
use edrard\WgGetter\Http\GuzzleTransport;
use edrard\WgGetter\RetryPolicy;

$getter = new WgDataGetter(
    transport: new GuzzleTransport(timeout: 15, connectTimeout: 5),
    multi: 5,
    retry: new RetryPolicy(maxAttempts: 3, baseDelay: 0.5, maxDelay: 30),
    logger: $logger,
);
```

The legacy edrard\\Curl\\Curl constructor dependency has been replaced by GuzzleTransport or an implementation of BatchTransportInterface.

## Behavioral guarantees

- multi is applied and must be between 1 and 10. Default timeouts are 15 seconds overall and 5 seconds to connect. TLS verification is enabled; redirects are disabled.
- The default limiter paces one instance at 10 requests/second, including retries. This is local pacing, not a distributed quota guarantee. Multiple workers must share a limiter configured for their application ID's actual quota.
- Transport failures, HTTP 429/500/502/503/504, and WG REQUEST_LIMIT_EXCEEDED / SOURCE_NOT_AVAILABLE are retried. Successful request keys are never fetched again during a retry.
- Three total attempts by default, with exponential delays and Retry-After. A Retry-After exceeding maxDelay is propagated without an early retry.
- Invalid parameters/IDs, malformed JSON and unexpected envelopes fail immediately with exceptions.
- getData() returns data blocks. getEnvelopes() preserves complete WG envelopes, including pagination meta.
- The queue is consumed on both success and failure. Exceptions do not return partial data; the application owns rescheduling.
- Numeric URL keys append; string keys identify requests. Conflicting string keys are rejected atomically.
- Remote URLs require HTTPS without userinfo or fragments. HTTP is allowed only for localhost tests. Supply trusted URLs, not arbitrary user input.
- Library log messages and exception text omit URLs, tokens, request keys, provider messages and raw JSON. User callbacks/loggers are responsible for their own output.
- A callback receives raw bodies and URLs once per successful batch. Normal mode requires same-key JSON strings, which are decoded again. getData($callback, true) returns the callback's own array and skips WG-envelope validation; result keys must be unique across batches.
- debugLog() is a deprecated no-op. Configure log levels on the logger.

Release: v2.0.0. Composer name: edrard/wggetter; stable constraint: ^2.0; development alias: 2.0.x-dev. Source: [Edrard/WgDataGetter](https://github.com/Edrard/WgDataGetter), Edrard, MIT. The new Laravel application has not adopted this package yet. Shared review: Docs/Reports/WG-LIBS-001_2026-09-26_review.md in the maintainer's workspace.

References: [WG envelopes and errors](https://developers.wargaming.net/documentation/guide/getting-started/), [Guzzle concurrent requests](https://docs.guzzlephp.org/en/stable/quickstart.html#concurrent-requests).
## Complete public-data example

```sh
php examples/public-data.php
# Configure WG_APPLICATION_ID or provide the application ID on stdin.
```

This explicitly makes one live read-only WoT request, preserving pagination/metadata and printing no application ID or account records. Unit tests do not use a live key.

### Compose with WgApi in a consuming application

Install both packages through your application's root Composer configuration. WgApi is intentionally not a dependency of this standalone URL fetcher. Until packages are registered on Packagist, declare both GitHub repositories explicitly:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Edrard/WgApi.git" },
        { "type": "vcs", "url": "https://github.com/Edrard/WgDataGetter.git" }
    ],
    "require": {
        "php": "^8.5",
        "edrard/wgapi": "^2.0",
        "edrard/wggetter": "^2.0"
    }
}
```

```php
require __DIR__.'/vendor/autoload.php';

use edrard\WgApi\GetWgApi;
use edrard\WgGetter\Exceptions\RequestException;
use edrard\WgGetter\WgDataGetter;

$id = getenv('WG_APPLICATION_ID') ?: throw new LogicException('Configure WG_APPLICATION_ID.');
$api = new GetWgApi(['eu' => $id]);
$api->changeUrlPrefix('stats_');
$getter = new WgDataGetter(multi: 5);
$getter->setUrls($api->getPlayerStat('eu', [500000001, 500000002], [], [
    'fields' => 'account_id,statistics.all',
]));
try {
    $batches = $getter->getData();
    foreach ($batches as $accounts) {
        foreach ($accounts as $accountId => $account) {
            if ($account === null) {
                continue; // WG may return null for a missing account.
            }
            // Pass $account to your application; do not dump player records.
        }
    }
} catch (RequestException $exception) {
    // Failure is explicit; the queue has already been consumed.
    // Reschedule deliberately using the saved input if your application needs it.
    $code = $exception->getCode();
    $retryable = $exception->retryable;
    $retryAfter = $exception->retryAfter;
}
```

### Pagination envelopes

```php
$getter->setUrls(['vehicles' => $api->getUrl('eu', 'wot', 'encyclopedia/vehicles', [
    'page_no' => 1, 'limit' => 100, 'fields' => 'tank_id',
])]);
$page = $getter->getEnvelopes()['vehicles'];
$vehicles = $page['data'];
$totalPages = $page['meta']['page_total'] ?? 1;
```

getEnvelopes() returns each full validated envelope; getData() returns just data. Neither automatically follows pagination. Use a bounded page loop, or WgParser's ApiTankCatalog for the vehicle catalogue. Do not run multiple unrelated consumers against the same queued getter instance simultaneously.

### Transport and quota injection

```php
use edrard\WgGetter\IntervalRateLimiter;
use edrard\WgGetter\RetryPolicy;

$getter = new WgDataGetter(
    multi: 3,
    retry: new RetryPolicy(maxAttempts: 2, baseDelay: 1, maxDelay: 10),
    limiter: new IntervalRateLimiter(requestsPerSecond: 5),
);
```

For multiple workers/packages using one application ID, supply a shared RateLimiterInterface implementation instead of one limiter per process. Endpoint and application quotas still govern actual throughput.

This package performs GET only. Generic public WG methods can be supplied as URLs; it does not choose HTTP verbs or grant private access. WgAuth provides POST authentication and avoids access tokens in URLs.
