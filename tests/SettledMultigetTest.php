<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\Tests\WgDataGetter\Fixtures\RecordingLimiter;
use edrard\Tests\WgDataGetter\Fixtures\ScriptedTransport;
use edrard\WgGetter\Http\HttpResult;
use edrard\WgGetter\Request;
use edrard\WgGetter\RetryPolicy;
use edrard\WgGetter\WgDataGetter;
use PHPUnit\Framework\TestCase;

final class SettledMultigetTest extends TestCase
{
    public function testPerRequestRetryOverrideCannotChangeSingleAttemptContract(): void
    {
        $transport = new ScriptedTransport([
            ['timeout' => new HttpResult(0, transportFailure: true)],
            ['timeout' => new HttpResult(200, '{"status":"ok","data":null}')],
        ]);
        $sleeps = [];
        $getter = new WgDataGetter($transport, limiter: new RecordingLimiter(), sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });
        $getter->setUrls(['timeout' => new Request('https://example.test/', retry: new RetryPolicy(5, 7, 60))]);

        $outcome = $getter->getEnvelopeOutcomesOnce()['timeout'];

        self::assertSame(1, $outcome->attempts);
        self::assertSame(0, $outcome->failure->code);
        self::assertCount(1, $transport->calls);
        self::assertSame([], $sleeps);
    }

    public function testDocumentedProviderIdentifierSurvivesWithoutRawErrorValue(): void
    {
        $transport = new ScriptedTransport([[
            'blocked' => new HttpResult(200, '{"status":"error","error":{"code":407,"message":"INVALID_IP_ADDRESS","field":"application_id","value":"secret-token"}}'),
            'unknown' => new HttpResult(200, '{"status":"error","error":{"code":407,"message":"secret-token"}}'),
            'missing' => new HttpResult(200, '{"status":"ok","meta":{"count":1},"data":{"6566456":null}}'),
            'no-data' => new HttpResult(200, '{"status":"ok"}'),
        ]]);
        $getter = new WgDataGetter($transport, limiter: new RecordingLimiter());
        $getter->setUrls(array_fill_keys(['blocked', 'unknown', 'missing', 'no-data'], 'https://example.test/'));

        $outcomes = $getter->getEnvelopeOutcomesOnce(4);

        self::assertSame('INVALID_IP_ADDRESS', $outcomes['blocked']->failure->providerMessage);
        self::assertNull($outcomes['unknown']->failure->providerMessage);
        self::assertTrue($outcomes['missing']->succeeded());
        self::assertSame(['6566456' => null], $outcomes['missing']->envelope()['data']);
        self::assertTrue($outcomes['no-data']->succeeded());
        self::assertStringNotContainsString('secret-token', var_export($outcomes['blocked']->failure, true));
    }

    public function testSingleAttemptReportsAllFailuresWithoutRetryOrCooldownAndRestoresQueue(): void
    {
        $transport = new ScriptedTransport([
            ['rate' => new HttpResult(429, retryAfter: 60), 'timeout' => new HttpResult(504), 'ok' => new HttpResult(200, '{"status":"ok","data":{"1":null}}')],
            ['later' => new HttpResult(200, '{"status":"ok","data":null}')],
        ]);
        $sleeps = [];
        $getter = new WgDataGetter($transport, retry: new RetryPolicy(3), limiter: new RecordingLimiter(), sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });
        $getter->setUrls(array_fill_keys(['rate', 'timeout', 'ok'], 'https://example.test/'));
        $outcomes = $getter->getEnvelopeOutcomesOnce(3);
        self::assertSame(429, $outcomes['rate']->failure->code);
        self::assertSame(60.0, $outcomes['rate']->failure->retryAfter);
        self::assertSame(504, $outcomes['timeout']->failure->code);
        self::assertTrue($outcomes['ok']->succeeded());
        self::assertSame(1, $outcomes['rate']->attempts);
        self::assertCount(1, $transport->calls);
        self::assertSame([], $sleeps);
        self::assertSame(10, $getter->getMultiVar());
        self::assertSame([], $getter->getEnvelopeOutcomesOnce());
        $getter->setUrls(['later' => 'https://example.test/']);
        self::assertTrue($getter->getEnvelopeOutcomes()['later']->succeeded());
    }

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
