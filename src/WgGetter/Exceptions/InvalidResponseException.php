<?php

declare(strict_types=1);

namespace edrard\WgGetter\Exceptions;

final class InvalidResponseException extends RequestException
{
    public function __construct(int|string $requestKey)
    {
        parent::__construct($requestKey, false);
    }
}
