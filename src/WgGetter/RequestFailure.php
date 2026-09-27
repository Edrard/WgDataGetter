<?php

declare(strict_types=1);

namespace edrard\WgGetter;

/** Sanitized terminal failure, without raw exception, URL, body or arbitrary provider text. */
final readonly class RequestFailure
{
    public function __construct(public string $kind, public int $code = 0, public bool $retryable = false, public ?float $retryAfter = null, public ?string $providerMessage = null)
    {
    }
}
