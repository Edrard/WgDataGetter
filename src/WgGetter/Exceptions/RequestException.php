<?php

declare(strict_types=1);

namespace edrard\WgGetter\Exceptions;

use RuntimeException;

class RequestException extends RuntimeException
{
    public function __construct(public readonly int|string $requestKey, public readonly bool $retryable, public readonly ?float $retryAfter = null, int $code = 0)
    {
        // Keys are caller-controlled too; neither keys, URLs nor provider messages enter the text.
        parent::__construct('API request failed (code '.$code.').', $code);
    }
}
