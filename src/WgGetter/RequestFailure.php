<?php

declare(strict_types=1);

namespace edrard\WgGetter;

/** Sanitized terminal failure, without raw exception, URL, body or provider message. */
final readonly class RequestFailure
{
    public function __construct(public string $kind, public int $code = 0, public bool $retryable = false, public ?float $retryAfter = null)
    {
    }
}
