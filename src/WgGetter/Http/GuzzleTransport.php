<?php

declare(strict_types=1);

namespace edrard\WgGetter\Http;

use edrard\WgGetter\Contracts\BatchTransportInterface;
use edrard\WgGetter\Request;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Throwable;

final class GuzzleTransport implements BatchTransportInterface
{
    private ClientInterface $client;
    public function __construct(?ClientInterface $client = null, private float $timeout = 120.0, private float $connectTimeout = 40.0)
    {
        if (!is_finite($timeout) || !is_finite($connectTimeout) || $timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException('HTTP timeouts must be finite and positive.');
        }
        $this->client = $client ?? new Client();
    }
    /**
     * @param array<int|string, string|Request> $urls
     * @return array<int|string, HttpResult>
     */
    public function send(#[SensitiveParameter] array $urls, int $concurrency): array
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException('Concurrency must be positive.');
        }
        $results = [];
        $requests = function () use ($urls): \Generator {
            foreach ($urls as $key => $request) {
                $url = $request instanceof Request ? $request->url() : $request;
                $timeout = $request instanceof Request ? ($request->timeout ?? $this->timeout) : $this->timeout;
                $connectTimeout = $request instanceof Request ? ($request->connectTimeout ?? $this->connectTimeout) : $this->connectTimeout;
                yield $key => fn () => $this->client->requestAsync('GET', $url, [
                    'debug' => false,
                    'query' => parse_url($url, PHP_URL_QUERY) ?? '',
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                    'http_errors' => false,
                    'allow_redirects' => false,
                    'verify' => true,
                    'headers' => ['Accept' => 'application/json'],
                ]);
            }
        };
        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, int|string $key) use (&$results): void {
                try {
                    $stream = $response->getBody();
                    if ($stream->isSeekable()) {
                        $stream->rewind();
                    }
                    $body = Utils::copyToString($stream);
                } catch (Throwable) {
                    $results[$key] = new HttpResult(0, transportFailure: true);
                    return;
                }
                $header = $response->getHeaderLine('Retry-After');
                $retryAfter = null;
                if ($header !== '') {
                    $retryAfter = ctype_digit($header) ? (float) $header : max(0.0, (float) (strtotime($header) ?: time()) - time());
                }
                $results[$key] = new HttpResult($response->getStatusCode(), $body, $retryAfter);
            },
            // Never retain the underlying exception: its message/request may contain credentials.
            'rejected' => function (mixed $reason, int|string $key) use (&$results): void {
                $results[$key] = new HttpResult(0, transportFailure: true);
            },
        ]);
        $pool->promise()->wait();
        return $results;
    }
}
