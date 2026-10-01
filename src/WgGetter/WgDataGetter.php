<?php

declare(strict_types=1);

namespace edrard\WgGetter;

use Closure;
use edrard\WgGetter\Contracts\BatchTransportInterface;
use edrard\WgGetter\Contracts\DataGetterInterface;
use edrard\WgGetter\Http\GuzzleTransport;
use edrard\WgGetter\Http\HttpResult;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

/** Executes the entire queued URL set in one asynchronous wave per attempt. */
final class WgDataGetter implements DataGetterInterface
{
    /** @var array<int|string, string|Request> */
    private array $urls = [];
    private BatchTransportInterface $transport;
    private RetryPolicy $retry;
    private LoggerInterface $logger;
    private Closure $sleep;
    private bool $running = false;

    public function __construct(
        ?BatchTransportInterface $transport = null,
        ?RetryPolicy $retry = null,
        ?LoggerInterface $logger = null,
        ?callable $sleep = null,
        float $timeout = 120.0,
        float $connectTimeout = 40.0,
    ) {
        if (!is_finite($timeout) || !is_finite($connectTimeout) || $timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException('HTTP timeouts must be finite and positive.');
        }
        $this->transport = $transport ?? new GuzzleTransport(timeout: $timeout, connectTimeout: $connectTimeout);
        $this->retry = $retry ?? new RetryPolicy();
        $this->logger = $logger ?? new NullLogger();
        $this->sleep = $sleep === null ? static function (float $seconds): void {
            usleep((int) ceil($seconds * 1e6));
        } : Closure::fromCallable($sleep);
    }

    /** @param array<array-key, mixed> $urls */
    public function setUrls(#[SensitiveParameter] array $urls): void
    {
        if ($this->running) {
            throw new LogicException('Cannot change the queue while fetching.');
        }
        $next = $this->urls;
        foreach ($urls as $key => $request) {
            if (!is_string($request) && !$request instanceof Request) {
                throw new InvalidArgumentException('Requests must be URL strings or Request instances.');
            }
            $url = $request instanceof Request ? $request->url() : $request;
            $parts = parse_url($url);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
                throw new InvalidArgumentException('Request URLs must be HTTP(S) URLs without credentials or fragments.');
            }
            if ($parts['scheme'] !== 'https' && !in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]'], true)) {
                throw new InvalidArgumentException('Remote requests require HTTPS.');
            }
            foreach (preg_split('/[&;]/', $parts['query'] ?? '') as $pair) {
                $name = urldecode(explode('=', $pair, 2)[0]);
                if (preg_match('/[\x00-\x1f\x7f]/', $name)) {
                    throw new InvalidArgumentException('Query parameter names must not contain control characters.');
                }
                if ($parts['scheme'] !== 'https' && preg_match('/^access[_. ]token(?:$|\[)/i', ltrim($name))) {
                    throw new InvalidArgumentException('Access token requests require HTTPS.');
                }
            }
            if (array_key_exists($key, $next)) {
                throw new InvalidArgumentException('Duplicate request key.');
            }
            $next[$key] = $request;
        }
        $this->urls = $next;
    }

    public function cleanUrls(): void
    {
        if ($this->running) {
            throw new LogicException('Cannot change the queue while fetching.');
        }
        $this->urls = [];
    }

    /** @return array<int|string, FetchResult> */
    public function getData(): array
    {
        if ($this->running) {
            throw new LogicException('Recursive fetching is not supported.');
        }
        $this->running = true;
        try {
            $pending = $this->urls;
            $results = [];
            for ($attempt = 1; $pending !== []; ++$attempt) {
                $responses = $this->transport->send($pending);
                $again = [];
                $delay = 0.0;
                foreach ($pending as $key => $request) {
                    $response = $responses[$key] ?? new HttpResult(0, transportFailure: true);
                    $policy = $request instanceof Request ? ($request->retry ?? $this->retry) : $this->retry;
                    $retryable = $response->transportFailure || in_array($response->status, [429, 500, 502, 503, 504], true);
                    if ($retryable && $attempt < $policy->maxAttempts) {
                        $again[$key] = $request;
                        $delay = max($delay, $policy->delay($attempt));
                        continue;
                    }
                    $results[$key] = new FetchResult($response, $attempt);
                }
                if ($again !== []) {
                    $this->logger->warning('Retrying transient HTTP failures.', ['count' => count($again), 'attempt' => $attempt, 'delay' => $delay]);
                    ($this->sleep)($delay);
                }
                $pending = $again;
            }
            $ordered = [];
            foreach ($this->urls as $key => $_) {
                $ordered[$key] = $results[$key];
            }
            return $ordered;
        } finally {
            $this->urls = [];
            $this->running = false;
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['queuedUrls' => '[redacted]'];
    }
}
