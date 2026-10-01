<?php

declare(strict_types=1);

namespace edrard\WgGetter\Http;

final readonly class HttpResult
{
    public function __construct(public int $status, public string $body = '', public ?float $retryAfter = null, public bool $transportFailure = false)
    {
    }

    /** @return array<string, int|float|bool|string|null> */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'body' => '[redacted]', 'retryAfter' => $this->retryAfter, 'transportFailure' => $this->transportFailure];
    }
}
