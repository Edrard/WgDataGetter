<?php

declare(strict_types=1);

namespace edrard\WgGetter\Http;

final readonly class HttpResult
{
    public function __construct(public int $status, public string $body = '', public ?float $retryAfter = null, public bool $transportFailure = false)
    {
    }
}
