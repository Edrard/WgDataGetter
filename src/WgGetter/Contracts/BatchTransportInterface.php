<?php

declare(strict_types=1);

namespace edrard\WgGetter\Contracts;

use edrard\WgGetter\Http\HttpResult;

interface BatchTransportInterface
{
    /**
     * @param array<int|string, string> $urls
     * @return array<int|string, HttpResult>
     */
    public function send(array $urls, int $concurrency): array;
}
