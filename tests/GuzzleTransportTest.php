<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\WgGetter\Http\GuzzleTransport;
use edrard\WgGetter\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleTransportTest extends TestCase
{
    public function testOneWaveUsesDefaultAndPerUrlTimeoutsWithoutChangingBodies(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"status":"ok"}'), new Response(504, [], 'gateway')]));
        $stack->push(Middleware::history($history));
        $transport = new GuzzleTransport(new Client(['handler' => $stack]));

        $results = $transport->send([
            'first' => 'https://example.test/first',
            'second' => new Request('https://example.test/second', timeout: 180, connectTimeout: 60),
        ]);

        self::assertCount(2, $history);
        self::assertSame(120.0, $history[0]['options']['timeout']);
        self::assertSame(40.0, $history[0]['options']['connect_timeout']);
        self::assertSame(180.0, $history[1]['options']['timeout']);
        self::assertSame(60.0, $history[1]['options']['connect_timeout']);
        self::assertSame('{"status":"ok"}', $results['first']->body);
        self::assertSame(504, $results['second']->status);
        self::assertSame('gateway', $results['second']->body);
    }

    public function testReadsLargeResponseCompletely(): void
    {
        $body = str_repeat('x', 9 * 1024 * 1024);
        $client = new Client(['handler' => new MockHandler([new Response(200, [], $body)])]);
        $result = (new GuzzleTransport($client))->send(['large' => 'https://example.test/large'])['large'];

        self::assertSame(strlen($body), strlen($result->body));
        self::assertSame($body, $result->body);
    }
}
