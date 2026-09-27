<?php

declare(strict_types=1);

namespace edrard\WgGetter\Exceptions;

use RuntimeException;

class RequestException extends RuntimeException
{
    public readonly ?string $providerMessage;

    public function __construct(public readonly int|string $requestKey, public readonly bool $retryable, public readonly ?float $retryAfter = null, int $code = 0, mixed $providerMessage = null)
    {
        // Only documented WG identifiers cross this boundary; field/value and raw text never do.
        $this->providerMessage = in_array($providerMessage, [
            'INVALID_IP_ADDRESS', 'INVALID_APPLICATION_ID', 'APPLICATION_IS_BLOCKED',
            'REQUEST_LIMIT_EXCEEDED', 'SOURCE_NOT_AVAILABLE',
        ], true) ? $providerMessage : null;
        // Keys are caller-controlled too; neither keys, URLs nor provider messages enter the text.
        parent::__construct('API request failed (code '.$code.').', $code);
    }
}
