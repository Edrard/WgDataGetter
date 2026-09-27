<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\WgGetter\Http\GuzzleTransport;
use edrard\WgGetter\Exceptions\RequestException;
use edrard\WgGetter\WgDataGetter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityReviewTest extends TestCase
{
    public function testGetResponseAboveFormerSizeLimitIsReturnedInFull(): void
    {
        $body = str_repeat('x', 9 * 1024 * 1024);
        $mock = new MockHandler([new Response(200, [], $body), new Response(200, [], 'unused')]);
        $transport = new GuzzleTransport(new Client(['handler' => HandlerStack::create($mock)]));
        $result = $transport->send(['a' => 'https://example.test'], 1)['a'];
        self::assertSame(200, $result->status);
        self::assertFalse($result->transportFailure);
        self::assertSame($body, $result->body);
        self::assertSame(1, $mock->count());
    }

    public static function tokenNames(): array
    {
        return [['access%5Ftoken'], ['%20access_token'], ['access.token'], ['access+token'], ['access_token%5B%5D']];
    }

    #[DataProvider('tokenNames')]
    public function testEncodedAccessTokenInHttpsUrlIsPreserved(string $name): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"status":"ok","data":null}')]));
        $stack->push(Middleware::history($history));
        $getter = new WgDataGetter(new GuzzleTransport(new Client(['handler' => $stack])));
        $getter->setUrls(['request' => 'https://example.test/?'.$name.'=fixture-secret']);
        self::assertSame(['request' => null], $getter->getData());
        self::assertSame($name.'=fixture-secret', $history[0]['request']->getUri()->getQuery());
    }

    public function testControlCharactersInQueryParameterNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WgDataGetter())->setUrls(['https://example.test/?access_token%00ignored=fixture-secret']);
    }

    public function testTokenCannotBeSentOverPlainHttp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WgDataGetter())->setUrls(['http://127.0.0.1/?access_token=fixture-secret']);
    }

    public function testInjectedClientCannotReplaceTheExplicitGetQuery(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack, 'query' => ['access_token' => 'fixture-secret'], 'debug' => true]);
        (new GuzzleTransport($client))->send(['a' => 'https://example.test/?application_id=fixture&search=Some%20Name'], 1);
        self::assertSame('application_id=fixture&search=Some%20Name', $history[0]['request']->getUri()->getQuery());
        self::assertFalse($history[0]['options']['debug']);
    }

    public function testAuthenticatedGetUrlIsRedactedInExceptionTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"status":"error","error":{"code":407,"message":"fixture-secret"}}')]));
            $getter = new WgDataGetter(new GuzzleTransport(new Client(['handler' => $stack])));
            $getter->setUrls(['request' => 'https://example.test/?access_token=fixture-secret']);
            try {
                $getter->getData();
                self::fail('Expected a WG error.');
            } catch (RequestException $exception) {
                $frames = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => $frame['function'] === 'fetchBatch'));
                self::assertInstanceOf(\SensitiveParameterValue::class, $frames[0]['args'][0]);
                $frames = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => $frame['function'] === 'envelope'));
                self::assertInstanceOf(\SensitiveParameterValue::class, $frames[0]['args'][0]);
                self::assertStringNotContainsString('fixture-secret', var_export($exception->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
