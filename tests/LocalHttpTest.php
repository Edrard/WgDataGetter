<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter;

use edrard\WgGetter\Http\GuzzleTransport;
use PHPUnit\Framework\TestCase;

final class LocalHttpTest extends TestCase
{
    public function testRealCurlConnectionsToLocalFixture(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Local HTTP integration requires proc_open.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $sink = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open([PHP_BINARY, '-S', $address, __DIR__.'/Fixtures/http-router.php'], [
            0 => ['pipe', 'r'], 1 => ['file', $sink, 'w'], 2 => ['file', $sink, 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $probe = @stream_socket_client('tcp://'.$address, $errno, $error, 0.01);
                if ($probe !== false) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            self::assertTrue($ready, 'Local fixture server did not start.');
            $results = (new GuzzleTransport())->send(['ok' => 'http://'.$address.'/ok', 'limited' => 'http://'.$address.'/limited'], 2);
            self::assertSame(200, $results['ok']->status);
            self::assertTrue(json_decode($results['ok']->body, true, flags: JSON_THROW_ON_ERROR)['data']['fixture']);
            self::assertSame(429, $results['limited']->status);
            self::assertSame(2.0, $results['limited']->retryAfter);
            foreach ([new \GuzzleHttp\Handler\CurlMultiHandler(), new \GuzzleHttp\Handler\StreamHandler()] as $handler) {
                $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($handler)]);
                $largeResponses = (new GuzzleTransport($http))->send([
                    'large' => 'http://'.$address.'/large',
                    'compressed' => 'http://'.$address.'/compressed',
                ], 2);
                foreach ($largeResponses as $result) {
                    self::assertSame(200, $result->status);
                    self::assertFalse($result->transportFailure);
                    self::assertSame(str_repeat('x', 9 * 1024 * 1024), $result->body);
                }
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
