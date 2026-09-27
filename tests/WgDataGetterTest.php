<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\Tests\WgDataGetter\Fixtures\ScriptedTransport;
use edrard\Tests\WgDataGetter\Fixtures\RecordingLimiter;
use edrard\WgGetter\Exceptions\InvalidResponseException;
use edrard\WgGetter\Exceptions\RequestException;
use edrard\WgGetter\Http\HttpResult;
use edrard\WgGetter\IntervalRateLimiter;
use edrard\WgGetter\RetryPolicy;
use edrard\WgGetter\WgDataGetter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class WgDataGetterTest extends TestCase
{
    private function getter(ScriptedTransport $transport, int $multi = 10, ?RecordingLimiter $limiter = null): WgDataGetter
    {
        return new WgDataGetter($transport, $multi, new RetryPolicy(3, 0, 0), $limiter ?? new RecordingLimiter(), sleep: static function (float $seconds): void {
        });
    }
    private static function ok(mixed $data, array $meta = []): HttpResult
    {
        return new HttpResult(200, json_encode(['status' => 'ok', 'data' => $data, 'meta' => $meta], JSON_THROW_ON_ERROR));
    }
    public function testConcurrencyKeysChunksAndQueueConsumption(): void
    {
        $transport = new ScriptedTransport([['a' => self::ok([1]), 'b' => self::ok(null)], ['c' => self::ok([3])]]);
        $limiter = new RecordingLimiter();
        $getter = $this->getter($transport, 2, $limiter);
        $getter->setUrls(['a' => 'https://api.example/a', 'b' => 'https://api.example/b', 'c' => 'https://api.example/c']);
        self::assertSame(['a' => [1], 'b' => null, 'c' => [3]], $getter->getData());
        self::assertSame(2, $getter->getMultiVar());
        self::assertSame([2, 1], $limiter->counts);
        self::assertSame([], $getter->getData());
        self::assertCount(2, $transport->calls);
    }
    public function testOnlyFailedKeysAreRetriedAndOrderIsRestored(): void
    {
        $transport = new ScriptedTransport([['a' => new HttpResult(503), 'b' => self::ok(['b'])], ['a' => self::ok(['a'])]]);
        $limiter = new RecordingLimiter();
        $getter = $this->getter($transport, limiter: $limiter);
        $getter->setUrls(['a' => 'https://api.example/a', 'b' => 'https://api.example/b']);
        self::assertSame(['a' => ['a'], 'b' => ['b']], $getter->getData());
        self::assertSame(['a'], array_keys($transport->calls[1][0]));
        self::assertSame([2, 1], $limiter->counts);
    }
    public static function permanentFailures(): array
    {
        return [
            [new HttpResult(200, '{"status":"error","error":{"code":407,"message":"INVALID_APPLICATION_ID"}}'), RequestException::class],
            [new HttpResult(404), RequestException::class],
            [new HttpResult(302), RequestException::class],
            [new HttpResult(200, '{invalid'), InvalidResponseException::class],
            [new HttpResult(200, 'null'), InvalidResponseException::class],
        ];
    }

    public function testSuccessfulEnvelopeWithoutDataIsReturnedAsNull(): void
    {
        $getter = $this->getter(new ScriptedTransport([['a' => new HttpResult(200, '{"status":"ok"}')]]));
        $getter->setUrls(['a' => 'https://api.example/a']);

        self::assertSame(['a' => null], $getter->getData());
    }
    #[DataProvider('permanentFailures')]
    public function testPermanentErrorsAreNotRetried(HttpResult $failure, string $exception): void
    {
        $transport = new ScriptedTransport([['a' => $failure]]);
        $getter = $this->getter($transport);
        $getter->setUrls(['a' => 'https://api.example/a']);
        try {
            $getter->getData();
            self::fail('Expected a request exception.');
        } catch (RequestException $error) {
            self::assertInstanceOf($exception, $error);
            self::assertCount(1, $transport->calls);
            self::assertSame([], $getter->getData());
        }
    }
    public static function transientFailures(): array
    {
        return [
            [new HttpResult(429)], [new HttpResult(0, transportFailure: true)],
            [new HttpResult(200, '{"status":"error","error":{"code":407,"message":"REQUEST_LIMIT_EXCEEDED"}}')],
            [new HttpResult(200, '{"status":"error","error":{"code":504,"message":"SOURCE_NOT_AVAILABLE"}}')],
        ];
    }
    #[DataProvider('transientFailures')]
    public function testTransientRetriesAreBounded(HttpResult $failure): void
    {
        $transport = new ScriptedTransport([['a' => $failure], ['a' => $failure], ['a' => $failure], ['a' => self::ok([])]]);
        $getter = $this->getter($transport);
        $getter->setUrls(['a' => 'https://api.example/a']);
        try {
            $getter->getData();
            self::fail('Expected exhausted retries.');
        } catch (RequestException $error) {
            self::assertTrue($error->retryable);
            self::assertCount(3, $transport->calls);
            self::assertSame([], $getter->getData());
        }
    }
    public function testNumericUrlsAppendAndDuplicateStringKeysFailAtomically(): void
    {
        $transport = new ScriptedTransport([[0 => self::ok(1), 1 => self::ok(2), 'a' => self::ok(3)]]);
        $getter = $this->getter($transport);
        $getter->setUrls(['https://api.example/1']);
        $getter->setUrls(['https://api.example/2', 'a' => 'https://api.example/3']);
        try {
            $getter->setUrls(['b' => 'https://api.example/4', 'a' => 'https://api.example/conflict']);
            self::fail('Expected duplicate key rejection.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([0 => 1, 1 => 2, 'a' => 3], $getter->getData());
    }
    public function testFullEnvelopesPreserveMetadata(): void
    {
        $getter = $this->getter(new ScriptedTransport([['a' => self::ok([], ['page_total' => 3])]]));
        $getter->setUrls(['a' => 'https://api.example/a']);
        self::assertSame(3, $getter->getEnvelopes()['a']['meta']['page_total']);
    }
    public function testRawModeWithCallableAndNormalTransformation(): void
    {
        $getter = $this->getter(new ScriptedTransport([['a' => new HttpResult(200, '{"results":[1]}')], ['a' => self::ok(1)]]));
        $getter->setUrls(['a' => 'https://api.example/a']);
        self::assertSame(['a' => ['results' => [1]]], $getter->getData(static fn (array $raw): array => array_map(static fn (string $body): mixed => json_decode($body, true, flags: JSON_THROW_ON_ERROR), $raw), true));
        $getter->setUrls(['a' => 'https://api.example/a']);
        self::assertSame(['a' => 2], $getter->getData(static fn (array $raw): array => ['a' => '{"status":"ok","data":2}']));
    }
    public function testCallbackFailureClearsQueue(): void
    {
        $getter = $this->getter(new ScriptedTransport([['a' => self::ok([])]]));
        $getter->setUrls(['a' => 'https://api.example/a']);
        try {
            $getter->getData(static fn (): array => throw new \RuntimeException('callback'));
        } catch (\RuntimeException) {
        }
        self::assertSame([], $getter->getData());
    }
    public function testNoCredentialsInExceptionOrLogs(): void
    {
        $logger = new class () extends AbstractLogger {
            public array $entries = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = [$level, (string) $message, $context];
            }
        };
        $transport = new ScriptedTransport([['secret-key' => new HttpResult(200, '{"status":"error","error":{"code":407,"message":"TOKEN=secret-value"}}')]]);
        $getter = new WgDataGetter($transport, limiter: new RecordingLimiter(), logger: $logger);
        $getter->setUrls(['secret-key' => 'https://api.example/a?access_token=secret-value']);
        try {
            $getter->getData();
            self::fail('Expected failure.');
        } catch (RequestException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertStringNotContainsString('secret', json_encode($logger->entries, JSON_THROW_ON_ERROR));
        }
    }
    public function testRateLimiterUsesMonotonicTimeAndBudgetsRetries(): void
    {
        $now = 100.0;
        $waits = [];
        $limiter = new IntervalRateLimiter(10, static function () use (&$now): float {
            return $now;
        }, static function (float $wait) use (&$now, &$waits): void {
            $waits[] = $wait;
            $now += $wait;
        });
        $limiter->acquire(10);
        $now += 0.2;
        $limiter->acquire(2);
        $limiter->acquire(1);
        self::assertEqualsWithDelta(0.8, $waits[0], 0.000001);
        self::assertEqualsWithDelta(0.2, $waits[1], 0.000001);
    }
    public function testBackoffAndRetryAfter(): void
    {
        $policy = new RetryPolicy(3, 0.5, 10);
        self::assertSame(0.5, $policy->delay(1));
        self::assertSame(1.0, $policy->delay(2));
        self::assertSame(5.0, $policy->delay(1, 5));
        self::assertSame(10.0, $policy->delay(30));
    }
    public static function invalidUrls(): array
    {
        return [['http://remote.example/x'], ['file:///tmp/x'], ['https://user:pass@example.com/x'], ['https://example.com/#fragment']];
    }
    #[DataProvider('invalidUrls')]
    public function testUnsafeUrlsAreRejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->getter(new ScriptedTransport([]))->setUrls([$url]);
    }
    public function testInvalidConcurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->getter(new ScriptedTransport([]), 0);
    }

    public function testRetryAfterIsHonored(): void
    {
        $waits = [];
        $transport = new ScriptedTransport([['a' => new HttpResult(429, retryAfter: 2.0)], ['a' => self::ok([])]]);
        $getter = new WgDataGetter($transport, retry: new RetryPolicy(), limiter: new RecordingLimiter(), sleep: static function (float $wait) use (&$waits): void {
            $waits[] = $wait;
        });
        $getter->setUrls(['a' => 'https://api.example/a']);
        self::assertSame(['a' => []], $getter->getData());
        self::assertSame([2.0], $waits);
    }

    public function testLongRetryAfterIsNotSilentlyShortened(): void
    {
        $transport = new ScriptedTransport([['a' => new HttpResult(429, retryAfter: 100.0)]]);
        $getter = new WgDataGetter($transport, limiter: new RecordingLimiter());
        $getter->setUrls(['a' => 'https://api.example/a']);
        try {
            $getter->getData();
            self::fail('Expected a delay beyond the retry budget.');
        } catch (RequestException $error) {
            self::assertSame(100.0, $error->retryAfter);
            self::assertCount(1, $transport->calls);
        }
    }

    public function testRawCallbackCannotSilentlyOverwriteAnotherBatch(): void
    {
        $transport = new ScriptedTransport([['a' => new HttpResult(200, '{}')], ['b' => new HttpResult(200, '{}')]]);
        $getter = $this->getter($transport, 1);
        $getter->setUrls(['a' => 'https://api.example/a', 'b' => 'https://api.example/b']);
        $this->expectException(InvalidArgumentException::class);
        $getter->getData(static fn (): array => ['same-key' => []], true);
    }
}
