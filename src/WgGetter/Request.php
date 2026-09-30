<?php

declare(strict_types=1);

namespace edrard\WgGetter;

use InvalidArgumentException;
use SensitiveParameter;

/** Per-URL transport and retry settings; omitted values inherit the getter defaults. */
final readonly class Request
{
    public function __construct(
        #[SensitiveParameter] private string $url,
        public ?float $timeout = null,
        public ?float $connectTimeout = null,
        public ?RetryPolicy $retry = null,
    ) {
        foreach ([$timeout, $connectTimeout] as $value) {
            if ($value !== null && (!is_finite($value) || $value <= 0)) {
                throw new InvalidArgumentException('HTTP timeouts must be finite and positive.');
            }
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @return array<string, string|float|int|null> */
    public function __debugInfo(): array
    {
        return [
            'url' => '[redacted]',
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'maxAttempts' => $this->retry?->maxAttempts,
        ];
    }
}
