# WgDataGetter

PHP 8.5 HTTP GET transport. Supply ready URLs; `getData()` returns a `FetchResult` for **every URL**, including failed requests. It never parses or validates WG JSON, extracts `data`, merges responses, or applies provider quotas.

The next breaking release is **3.0.0 (unreleased worktree)**. Package name: `edrard/wggetter`; after publication, use `^3.0`.

```php
use edrard\WgGetter\WgDataGetter;

$getter = new WgDataGetter();
$getter->setUrls([
    'profile' => $profileUrl,
    'tanks' => $tanksUrl,
]);
$results = $getter->getData();

foreach ($results as $key => $result) {
    // $result->body: complete unchanged HTTP body, or null for transport failure.
    // $result->httpStatus: HTTP code, or null for a transport failure.
    // $result->attempts: number of attempts for this URL.
    // $result->transportFailure: true when no complete usable response was obtained.
}
```

One URL is one GET. More than one URL is submitted together as **one asynchronous multirequest**. By default, a timeout, network failure, or HTTP `429`, `500`, `502`, `503`, `504` receives up to three total attempts, with pauses of 5 and 10 seconds. Only failed URLs are sent again in the next wave. A successful URL is never repeated because another URL failed. Other HTTP statuses are returned after the first attempt. An HTTP 200 body containing WG `status: error` is returned unchanged. A malformed JSON body is also returned unchanged.

There is no built-in request-per-second cap or maximum number of concurrent URLs. The caller controls the number of URLs passed in each call. This is separate from per-URL retry behavior. The queue is consumed after `getData()`, including if infrastructure code raises an exception.

Connection timeout defaults to 40 seconds; the full HTTP timeout defaults to 120 seconds. Neither limits response bytes. Configure timeouts and retry policy at construction or for an individual URL:

```php
use edrard\WgGetter\Request;
use edrard\WgGetter\RetryPolicy;
use edrard\WgGetter\WgDataGetter;

$getter = new WgDataGetter(
    timeout: 120,
    connectTimeout: 40,
    retry: new RetryPolicy(maxAttempts: 3, baseDelay: 5, maxDelay: 30),
);
$getter->setUrls([
    'normal' => $normalUrl,
    'large' => new Request(
        $largeUrl,
        timeout: 180,
        connectTimeout: 60,
        retry: new RetryPolicy(maxAttempts: 4, baseDelay: 8, maxDelay: 60),
    ),
]);
$results = $getter->getData();
```

`FetchResult::succeeded()` means HTTP 2xx with an actual response; it makes **no claim about WG's `status` field**. HTTP error bodies are retained. For a transport failure, `body` and `httpStatus` are null. Request keys and input order are preserved. Invalid URL/configuration values raise exceptions before sending. Remote requests require HTTPS; TLS verification is on and redirects are disabled. URL, token and body values are redacted from the library's debug output; consumers must also avoid logging raw private values.

MIT license. Source: [Edrard/WgDataGetter](https://github.com/Edrard/WgDataGetter). Development checks: `composer test`, `composer analyse`, `composer format:check`, `composer validate --strict` on PHP 8.5.

## Public API and queue

Load Composer's `vendor/autoload.php`. Runtime requirements are PHP `^8.5`, `ext-curl`, `ext-ctype`, Guzzle `^7.10`, PSR-7 implementation `^2.11` and PSR logger `^3.0`, as declared in `composer.json`.

Classes use namespace `edrard\WgGetter`; interfaces use `edrard\WgGetter\Contracts`.

| Operation | Contract |
| --- | --- |
| `WgDataGetter::__construct(?BatchTransportInterface $transport = null, ?RetryPolicy $retry = null, ?LoggerInterface $logger = null, ?callable $sleep = null, float $timeout = 120.0, float $connectTimeout = 40.0)` | Uses default Guzzle transport, retry policy, null logger and real sleep when omitted. Injected transport controls its own timeouts. |
| `DataGetterInterface::setUrls(array $urls): void` | Appends keyed URL strings or `Request` objects to the queue; preserves keys and insertion order. Duplicate keys, including numeric keys from repeated calls, are rejected. Invalid additions leave the existing queue unchanged. |
| `DataGetterInterface::cleanUrls(): void` | Discards the pending queue. |
| `DataGetterInterface::getData(): array` | Consumes the queue, returning `array<int|string, FetchResult>`. An empty queue returns `[]`. |
| `Request::__construct(string $url, ?float $timeout = null, ?float $connectTimeout = null, ?RetryPolicy $retry = null)` | Immutable per-URL settings. Null settings inherit defaults; URL validation occurs in `setUrls()`. |
| `Request::url(): string` | Returns the original URL. |
| `RetryPolicy::__construct(int $maxAttempts = 3, float $baseDelay = 5.0, float $maxDelay = 30.0)` | Immutable policy; attempts include the first request. |
| `RetryPolicy::delay(int $attempt, ?float $retryAfter = null): float` | Delay after a failed attempt, capped at `maxDelay`. |
| `FetchResult::__construct(Http\HttpResult $response, int $attempts)` | Immutable final result, normally constructed by Getter. |
| `FetchResult::succeeded(): bool` | True for HTTP 2xx without transport failure. |

The queue cannot be changed while `getData()` is running; recursive fetching also throws `LogicException`. Transport, logger or custom sleep exceptions propagate to the caller, and the queue is still cleared. Timeout and retry configuration errors raise `InvalidArgumentException`: timeouts must be finite and positive; attempts at least 1; delays finite and non-negative, with `maxDelay >= baseDelay`.

Retry delay is `min(maxDelay, baseDelay * 2^(attempt-1))`, with exponent capped at 30. A wave waits for the largest configured delay among its pending retries. `Retry-After` is exposed on the final result but **Getter does not use it to schedule retries**. Although `delay()` accepts a header-derived argument for independent use, Getter calls it without that argument.

## Result examples

`body` is a string, not a decoded array. These illustrative values show the public fields returned for one key:

| Field | Successful HTTP response | Final HTTP error | Final transport failure |
| --- | --- | --- | --- |
| `httpStatus` | `200` | `504` | `null` |
| `body` | `' {"status":"ok","data":{"1":null}} '` | Original error body, e.g. `'Gateway Timeout'` | `null` |
| `transportFailure` | `false` | `false` | `true` |
| `attempts` | `1` | `3` with default retry policy | `3` with default retry policy |
| `retryAfter` | `null` or parsed header seconds | `null` or parsed header seconds | Normally `null` |
| `succeeded()` | `true` | `false` | `false` |

Transport failure also covers an unreadable response body. The transport does not retain the underlying exception, so the result cannot distinguish DNS failure, connection timeout and body-read failure. Debug output redacts bodies and URLs; reading the public `body` property still returns the original contents.

## Transport extension point

`Contracts\BatchTransportInterface::send(array $urls): array` accepts keyed URL strings or `Request` objects and returns keyed `Http\HttpResult` objects. `HttpResult::__construct(int $status, string $body = '', ?float $retryAfter = null, bool $transportFailure = false)` exposes those four immutable public properties. An omitted result key is treated by Getter as a transport failure and follows the normal retry policy.

The default `Http\GuzzleTransport::__construct(?GuzzleHttp\ClientInterface $client = null, float $timeout = 120.0, float $connectTimeout = 40.0)` implements `send()`. It submits all supplied URLs using a Guzzle pool; actual parallel I/O depends on the configured Guzzle handler. Request options enforce TLS verification, disabled redirects and disabled HTTP-status exceptions. Plain HTTP is accepted only for `localhost`, `127.0.0.1` and `[::1]` testing; access-token requests always require HTTPS. No response-size cap is imposed.

After publication, install with `composer require edrard/wggetter:^3.0`. For this unpublished checkout, use a root Composer path repository with version `3.0.x-dev` and requirement `^3.0@dev`; see the [three-package local setup](../WotClient/README.md#installation).

`php examples/public-data.php` performs one public WG request using `WG_APPLICATION_ID` from the environment and prints HTTP status, attempts and response byte count. It uses normal default timeouts and retries. Files in the older singular `example/` directory use obsolete interfaces and are not compatible with 3.x.
