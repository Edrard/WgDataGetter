<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\WgGetter\Http\GuzzleTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleTransportTest extends TestCase
{
    public function testPoolBoundsActualOutstandingHttpPromises(): void
    {
        $active = $peak = $started = 0;
        $handler = static function () use (&$active, &$peak, &$started): \GuzzleHttp\Promise\Promise {
            ++$active;
            ++$started;
            $peak = max($peak, $active);
            $promise = null;
            $promise = new \GuzzleHttp\Promise\Promise(static function () use (&$promise, &$active): void {
                --$active;
                $promise->resolve(new Response(200, [], '{"status":"ok","data":[]}'));
            });
            return $promise;
        };
        $transport = new GuzzleTransport(new Client(['handler' => $handler]));
        $results = $transport->send(array_fill(0, 13, 'https://example.test/'), 3);
        self::assertCount(13, $results);
        self::assertSame(13, $started);
        self::assertSame(3, $peak);
        self::assertSame(0, $active);
    }

    public function testAsyncPoolPreservesKeysHttpErrorsAndSecureOptions(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], 'ok'), new Response(429, ['Retry-After' => '5']), new ConnectException('secret-url', new Request('GET', 'https://example.test/?access_token=secret'))]));
        $handler->push(Middleware::history($history));
        $transport = new GuzzleTransport(new Client(['handler' => $handler]));
        $results = $transport->send(['a' => 'https://example.test/a', 'b' => 'https://example.test/b', 'c' => 'https://example.test/c'], 2);
        self::assertSame('ok', $results['a']->body);
        self::assertSame(429, $results['b']->status);
        self::assertSame(5.0, $results['b']->retryAfter);
        self::assertTrue($results['c']->transportFailure);
        self::assertSame('', $results['c']->body);
        foreach ($history as $entry) {
            self::assertFalse($entry['options']['http_errors']);
            self::assertFalse($entry['options']['allow_redirects']);
            self::assertTrue($entry['options']['verify']);
            self::assertSame(15.0, $entry['options']['timeout']);
        }
    }
}
