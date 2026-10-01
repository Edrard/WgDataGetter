<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\WgGetter\Contracts\BatchTransportInterface;
use edrard\WgGetter\Http\HttpResult;
use edrard\WgGetter\Request;
use edrard\WgGetter\RetryPolicy;
use edrard\WgGetter\WgDataGetter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GetterContractTest extends TestCase
{
    public function testQueueAppendsAtomicallyAndCleanUrlsDiscardsPendingRequests(): void
    {
        $transport = new ScriptedTransport([['a' => new HttpResult(200), 'b' => new HttpResult(200)]]);
        $getter = new WgDataGetter($transport);
        $getter->setUrls(['a' => 'https://example.test/a']);
        $getter->setUrls(['b' => 'https://example.test/b']);
        try {
            $getter->setUrls(['c' => 'https://example.test/c', 'a' => 'https://example.test/duplicate']);
            self::fail('Duplicate keys must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(['a', 'b'], array_keys($getter->getData()));
        }
        self::assertSame([['a', 'b']], $transport->calls);
        self::assertSame([], $getter->getData());
        $getter->setUrls(['discarded' => 'https://example.test/discarded']);
        $getter->cleanUrls();
        self::assertSame([], $getter->getData());
        self::assertSame([['a', 'b']], $transport->calls);
    }

    public function testInfrastructureExceptionStillConsumesQueue(): void
    {
        $getter = new WgDataGetter(new class () implements BatchTransportInterface {
            public function send(array $urls): array
            {
                throw new \RuntimeException('Infrastructure failure.');
            }
        });
        $getter->setUrls(['a' => 'https://example.test/a']);
        try {
            $getter->getData();
            self::fail('Infrastructure exceptions must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Infrastructure failure.', $exception->getMessage());
        }
        self::assertSame([], $getter->getData());
    }

    public function testMultirequestRetriesOnlyFailuresAndNeverLosesSuccessfulBodies(): void
    {
        $first = [];
        for ($id = 1; $id <= 8; ++$id) {
            $first[$id] = new HttpResult(200, '{"status":"ok","data":null}');
        }
        $first[9] = new HttpResult(0, transportFailure: true);
        $first[10] = new HttpResult(504, '{"status":"error","error":{"code":504}}');
        $transport = new ScriptedTransport([
            $first,
            [9 => new HttpResult(200, '{"status":"error","error":{"message":"WG decides"}}'), 10 => new HttpResult(504, 'gateway')],
            [10 => new HttpResult(504, 'still unavailable')],
        ]);
        $sleeps = [];
        $getter = new WgDataGetter($transport, sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });
        $getter->setUrls(array_combine(range(1, 10), array_map(static fn (int $id): string => "https://example.test/$id", range(1, 10))));

        $results = $getter->getData();

        self::assertCount(10, $results);
        self::assertSame([range(1, 10), [9, 10], [10]], $transport->calls);
        self::assertSame([5.0, 10.0], $sleeps);
        self::assertSame(1, $results[1]->attempts);
        self::assertSame(2, $results[9]->attempts);
        self::assertSame('{"status":"error","error":{"message":"WG decides"}}', $results[9]->body);
        self::assertTrue($results[9]->succeeded());
        self::assertSame(3, $results[10]->attempts);
        self::assertSame(504, $results[10]->httpStatus);
        self::assertSame('still unavailable', $results[10]->body);
        self::assertFalse($results[10]->succeeded());
        self::assertSame([], $getter->getData());
    }

    public function testTransportFailureRemainsVisibleAfterThreeAttempts(): void
    {
        $transport = new ScriptedTransport([
            ['a' => new HttpResult(0, transportFailure: true)],
            ['a' => new HttpResult(0, transportFailure: true)],
            ['a' => new HttpResult(0, transportFailure: true)],
        ]);
        $getter = new WgDataGetter($transport, sleep: static function (): void {
        });
        $getter->setUrls(['a' => 'https://example.test/a']);

        $result = $getter->getData()['a'];

        self::assertNull($result->httpStatus);
        self::assertNull($result->body);
        self::assertTrue($result->transportFailure);
        self::assertSame(3, $result->attempts);
    }

    public function testPerRequestRetrySettingsOverrideDefaults(): void
    {
        $transport = new ScriptedTransport([
            ['short' => new HttpResult(429), 'normal' => new HttpResult(500)],
            ['short' => new HttpResult(429), 'normal' => new HttpResult(500)],
            ['normal' => new HttpResult(200, 'not JSON at all')],
        ]);
        $sleeps = [];
        $getter = new WgDataGetter($transport, sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });
        $getter->setUrls([
            'short' => new Request('https://example.test/short', retry: new RetryPolicy(2, 7, 30)),
            'normal' => 'https://example.test/normal',
        ]);

        $results = $getter->getData();

        self::assertSame([7.0, 10.0], $sleeps);
        self::assertSame(2, $results['short']->attempts);
        self::assertSame(429, $results['short']->httpStatus);
        self::assertSame(3, $results['normal']->attempts);
        self::assertSame('not JSON at all', $results['normal']->body);
    }

    public function testNonTransientHttpErrorIsReturnedWithoutRetry(): void
    {
        $transport = new ScriptedTransport([['bad' => new HttpResult(400, '{"status":"error"}')]]);
        $getter = new WgDataGetter($transport);
        $getter->setUrls(['bad' => 'https://example.test/bad']);

        $result = $getter->getData()['bad'];

        self::assertSame(1, $result->attempts);
        self::assertSame(400, $result->httpStatus);
        self::assertSame('{"status":"error"}', $result->body);
        self::assertCount(1, $transport->calls);
    }

    public function testRejectsUntrustedUrlBeforeSending(): void
    {
        $getter = new WgDataGetter(new ScriptedTransport([]));
        $this->expectException(InvalidArgumentException::class);
        $getter->setUrls(['bad' => 'http://external.example/token']);
    }

    public function testRejectsTokenOverLoopbackHttp(): void
    {
        $getter = new WgDataGetter(new ScriptedTransport([]));
        $this->expectException(InvalidArgumentException::class);
        $getter->setUrls(['bad' => 'http://localhost/wot/account/info/?access_token=secret']);
    }

    public function testDebugOutputHidesQueuedUrlsAndBodies(): void
    {
        $getter = new WgDataGetter(new ScriptedTransport([['private' => new HttpResult(200, 'private-response')]]));
        $getter->setUrls(['private' => new Request('https://example.test/?access_token=secret')]);
        ob_start();
        var_dump($getter);
        $getterDebug = (string) ob_get_clean();
        $result = $getter->getData()['private'];
        ob_start();
        var_dump($result);
        $resultDebug = (string) ob_get_clean();

        self::assertStringNotContainsString('secret', $getterDebug);
        self::assertStringNotContainsString('private-response', $resultDebug);
        self::assertSame('private-response', $result->body);
    }
}

/** @internal */
final class ScriptedTransport implements BatchTransportInterface
{
    /** @var list<list<int|string>> */
    public array $calls = [];

    /** @param list<array<int|string, HttpResult>> $waves */
    public function __construct(private array $waves)
    {
    }

    /** @param array<int|string, string|Request> $urls @return array<int|string, HttpResult> */
    public function send(array $urls): array
    {
        $this->calls[] = array_keys($urls);
        return array_shift($this->waves) ?? [];
    }
}
