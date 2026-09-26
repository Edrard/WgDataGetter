<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter\Fixtures;

use edrard\WgGetter\Contracts\BatchTransportInterface;

final class ScriptedTransport implements BatchTransportInterface
{
    public array $calls = [];
    public function __construct(private array $responses)
    {
    }
    public function send(array $urls, int $concurrency): array
    {
        $this->calls[] = [$urls, $concurrency];
        return array_shift($this->responses) ?? [];
    }
}
