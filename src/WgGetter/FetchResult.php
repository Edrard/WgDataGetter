<?php

declare(strict_types=1);

namespace edrard\WgGetter;

use edrard\WgGetter\Http\HttpResult;

/** One URL's final HTTP result after its configured attempts. */
final readonly class FetchResult
{
    public ?int $httpStatus;
    public ?string $body;
    public bool $transportFailure;
    public ?float $retryAfter;

    public function __construct(HttpResult $response, public int $attempts)
    {
        $this->httpStatus = $response->transportFailure ? null : $response->status;
        $this->body = $response->transportFailure ? null : $response->body;
        $this->transportFailure = $response->transportFailure;
        $this->retryAfter = $response->retryAfter;
    }

    public function succeeded(): bool
    {
        return !$this->transportFailure && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /** @return array<string, int|bool|string|null> */
    public function __debugInfo(): array
    {
        return [
            'httpStatus' => $this->httpStatus,
            'body' => '[redacted]',
            'transportFailure' => $this->transportFailure,
            'attempts' => $this->attempts,
        ];
    }
}
