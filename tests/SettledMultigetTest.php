<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\Tests\WgDataGetter\Fixtures\RecordingLimiter;
use edrard\Tests\WgDataGetter\Fixtures\ScriptedTransport;
use edrard\WgGetter\Http\HttpResult;
use edrard\WgGetter\RetryPolicy;
use edrard\WgGetter\WgDataGetter;
use PHPUnit\Framework\TestCase;

final class SettledMultigetTest extends TestCase
{
    public function testFailuresRetainSuccessesRetryOnlyPendingKeysAndConsumeQueue(): void
    {
        $ok = new HttpResult(200, '{"status":"ok","data":{"1":null}}');
        $transport = new ScriptedTransport([
            ['good' => $ok, 'bad' => new HttpResult(404), 'retry' => new HttpResult(429), 'json' => new HttpResult(200, '{bad')],
            ['retry' => new HttpResult(503)],
            ['retry' => $ok],
            ['later' => new HttpResult(503)],
            ['later' => new HttpResult(503)],
            ['later' => new HttpResult(503)],
        ]);
        $getter = new WgDataGetter($transport, 10, new RetryPolicy(3, 0, 0), new RecordingLimiter(), sleep: static function (float $seconds): void {
        });
        $urls = array_fill_keys(['good', 'bad', 'retry', 'json', 'later'], 'https://example.test/');
        $getter->setUrls($urls);
        $outcomes = $getter->getEnvelopeOutcomes(4);
        self::assertSame(array_keys($urls), array_keys($outcomes));
        self::assertTrue($outcomes['good']->succeeded());
        self::assertSame([1 => null], $outcomes['good']->envelope()['data']);
        self::assertSame(404, $outcomes['bad']->failure->code);
        self::assertFalse($outcomes['bad']->failure->retryable);
        self::assertSame('invalid_response', $outcomes['json']->failure->kind);
        self::assertSame(1, $outcomes['json']->attempts);
        self::assertTrue($outcomes['retry']->succeeded());
        self::assertSame(3, $outcomes['retry']->attempts);
        self::assertSame(3, $outcomes['later']->attempts);
        self::assertTrue($outcomes['later']->failure->retryable);
        self::assertSame(['retry'], array_keys($transport->calls[1][0]));
        self::assertSame(['later'], array_keys($transport->calls[3][0]));
        self::assertSame(4, $transport->calls[0][1]);
        self::assertSame(10, $getter->getMultiVar());
        self::assertSame([], $getter->getEnvelopeOutcomes());
        self::assertSame([], $getter->getData());
    }
}
