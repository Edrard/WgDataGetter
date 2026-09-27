<?php

declare(strict_types=1);

namespace edrard\WgGetter;

use Closure;
use edrard\WgGetter\Contracts\BatchTransportInterface;
use edrard\WgGetter\Contracts\SingleAttemptDataGetterInterface;
use edrard\WgGetter\Exceptions\InvalidResponseException;
use edrard\WgGetter\Contracts\RateLimiterInterface;
use edrard\WgGetter\Exceptions\RequestException;
use edrard\WgGetter\Http\GuzzleTransport;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

class WgDataGetter implements SingleAttemptDataGetterInterface
{
    /** @var array<int|string, string> */
    private array $urls = [];
    private int $multi;
    private BatchTransportInterface $transport;
    private RetryPolicy $retry;
    private RateLimiterInterface $limiter;
    private ResponseDecoder $decoder;
    private LoggerInterface $logger;
    private Closure $sleep;
    private bool $running = false;

    public function __construct(?BatchTransportInterface $transport = null, int $multi = 10, ?RetryPolicy $retry = null, ?RateLimiterInterface $limiter = null, ?LoggerInterface $logger = null, ?callable $sleep = null)
    {
        $this->setMultiVar($multi);
        $this->transport = $transport ?? new GuzzleTransport();
        $this->retry = $retry ?? new RetryPolicy();
        $this->limiter = $limiter ?? new IntervalRateLimiter();
        $this->decoder = new ResponseDecoder();
        $this->logger = $logger ?? new NullLogger();
        $this->sleep = $sleep === null ? static function (float $seconds): void {
            usleep((int) ceil($seconds * 1e6));
        } : Closure::fromCallable($sleep);
    }

    /** @deprecated Configure log levels on the injected PSR-3 logger. */
    public function debugLog(): void
    {
    }
    public function setMultiVar(int $multi): void
    {
        if ($multi < 1 || $multi > 10) {
            throw new InvalidArgumentException('Batch concurrency must be between 1 and 10. Coordinate higher quotas in the application.');
        }
        $this->multi = $multi;
    }
    public function getMultiVar(): int
    {
        return $this->multi;
    }

    /**
     * String keys are stable identifiers; numeric keys are appended without overwriting earlier URLs.
     * @param array<array-key, mixed> $urls
     */
    public function setUrls(#[SensitiveParameter] array $urls): void
    {
        if ($this->running) {
            throw new LogicException('Cannot change the queue while fetching.');
        }
        $next = $this->urls;
        foreach ($urls as $key => $url) {
            $parts = is_string($url) ? parse_url($url) : false;
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
            if (is_int($key)) {
                $next[] = $url;
            } elseif (array_key_exists($key, $next) && $next[$key] !== $url) {
                throw new InvalidArgumentException('Duplicate request key with a different URL.');
            } else {
                $next[$key] = $url;
            }
        }
        $this->urls = $next;
    }
    public function cleanUrls(): void
    {
        $this->urls = [];
    }

    /**
     * Normal mode returns keyed WG data. The callback receives raw bodies and URLs
     * once per successful batch. With $instead=true, it supplies the output itself.
     * Failures throw; the queue is always consumed and cleared.
     * @return array<array-key, mixed>
     */
    public function getData(?callable $function = null, bool $instead = false): array
    {
        return $this->execute($function, $instead, false);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getEnvelopes(): array
    {
        return $this->execute(null, false, true);
    }

    /** @return array<int|string, RequestOutcome> */
    public function getEnvelopeOutcomes(?int $concurrency = null): array
    {
        return $this->settled($concurrency, $this->retry->maxAttempts);
    }

    /** @return array<int|string, RequestOutcome> */
    public function getEnvelopeOutcomesOnce(?int $concurrency = null): array
    {
        return $this->settled($concurrency, 1);
    }

    /** @return array<int|string, RequestOutcome> */
    private function settled(?int $concurrency, int $maxAttempts): array
    {
        if ($this->running) {
            throw new LogicException('Recursive fetching is not supported.');
        }
        $previous = $this->multi;
        if ($concurrency !== null) {
            $this->setMultiVar($concurrency);
        }
        $this->running = true;
        try {
            $outcomes = [];
            foreach (array_chunk($this->urls, $this->multi, true) as $urls) {
                [, , $batch] = $this->fetchBatch($urls, true, true, $maxAttempts);
                $outcomes += $batch;
            }
            return $outcomes;
        } finally {
            $this->cleanUrls();
            $this->running = false;
            $this->multi = $previous;
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function execute(?callable $function, bool $instead, bool $envelopes): array
    {
        if ($this->running) {
            throw new LogicException('Recursive fetching is not supported.');
        }
        $this->running = true;
        try {
            $end = [];
            foreach (array_chunk($this->urls, $this->multi, true) as $urls) {
                [$raw, $data] = $this->fetchBatch($urls, !$instead);
                $processed = $function === null ? $raw : $function($raw, $urls);
                if (!is_array($processed)) {
                    throw new InvalidArgumentException('Response callback must return an array.');
                }
                if (!$instead && $function !== null) {
                    $data = [];
                    foreach ($urls as $key => $_) {
                        if (!isset($processed[$key]) || !is_string($processed[$key])) {
                            throw new InvalidArgumentException('Normal-mode callbacks must preserve keys and return JSON strings.');
                        }
                        $data[$key] = $this->decoder->envelope($processed[$key], $key);
                    }
                }
                if (!$instead && !$envelopes) {
                    foreach ($data as &$envelope) {
                        $envelope = $envelope['data'] ?? null;
                    }
                    unset($envelope);
                }
                if ($instead && array_intersect_key($end, $processed) !== []) {
                    throw new InvalidArgumentException('Callback result keys must be unique across batches.');
                }
                $end = array_replace($end, $instead ? $processed : $data);
            }
            return $end;
        } finally {
            $this->cleanUrls();
            $this->running = false;
        }
    }

    /**
     * @return array{array<array-key, mixed>, array<array-key, mixed>, array<int|string, RequestOutcome>}
     * @param array<int|string, string> $urls
     */
    private function fetchBatch(#[SensitiveParameter] array $urls, bool $validateEnvelope, bool $settled = false, ?int $maxAttempts = null): array
    {
        $pending = $urls;
        $raw = $data = $outcomes = [];
        for ($attempt = 1; $pending !== []; ++$attempt) {
            $this->limiter->acquire(count($pending));
            $responses = $this->transport->send($pending, $this->multi);
            $retryUrls = [];
            $delay = 0.0;
            foreach ($pending as $key => $url) {
                try {
                    $response = $responses[$key] ?? throw new RequestException($key, true);
                    if ($response->transportFailure || $response->status < 200 || $response->status >= 300) {
                        throw new RequestException($key, $response->transportFailure || in_array($response->status, [429, 500, 502, 503, 504], true), $response->retryAfter, $response->status);
                    }
                    $value = $validateEnvelope ? $this->decoder->envelope($response->body, $key) : null;
                    $raw[$key] = $response->body;
                    $data[$key] = $value;
                    if ($settled) {
                        $outcomes[$key] = new RequestOutcome($value, null, $attempt);
                    }
                } catch (InvalidResponseException $error) {
                    if (!$settled) {
                        throw $error;
                    }
                    $outcomes[$key] = new RequestOutcome(null, new RequestFailure('invalid_response'), $attempt);
                } catch (RequestException $error) {
                    if (!$error->retryable || $attempt >= ($maxAttempts ?? $this->retry->maxAttempts) || ($error->retryAfter !== null && $error->retryAfter > $this->retry->maxDelay)) {
                        $this->logger->error('API request failed.', ['code' => $error->getCode(), 'attempt' => $attempt]);
                        if (!$settled) {
                            throw $error;
                        }
                        $outcomes[$key] = new RequestOutcome(null, new RequestFailure('request_failed', $error->getCode(), $error->retryable, $error->retryAfter, $error->providerMessage), $attempt);
                        continue;
                    }
                    $retryUrls[$key] = $url;
                    $delay = max($delay, $this->retry->delay($attempt, $error->retryAfter));
                }
            }
            if ($retryUrls !== []) {
                $this->logger->warning('Retrying transient API failures.', ['count' => count($retryUrls), 'attempt' => $attempt, 'delay' => $delay]);
                ($this->sleep)($delay);
            }
            $pending = $retryUrls;
        }
        // Restore input order even when a later retry finished after other keys.
        $orderedRaw = $orderedData = [];
        foreach ($urls as $key => $_) {
            if (array_key_exists($key, $raw)) {
                $orderedRaw[$key] = $raw[$key];
                $orderedData[$key] = $data[$key];
            }
        }
        $orderedOutcomes = [];
        foreach ($urls as $key => $_) {
            if (isset($outcomes[$key])) {
                $orderedOutcomes[$key] = $outcomes[$key];
            }
        }
        return [$orderedRaw, $orderedData, $orderedOutcomes];
    }
}
