<?php

declare(strict_types=1);

namespace edrard\WgGetter;

use edrard\WgGetter\Exceptions\InvalidResponseException;
use edrard\WgGetter\Exceptions\RequestException;
use JsonException;
use SensitiveParameter;

final class ResponseDecoder
{
    public function decode(#[SensitiveParameter] string $body, int|string $key): mixed
    {
        return $this->envelope($body, $key)['data'];
    }
    /**
     * @return array<array-key, mixed>
     */
    public function envelope(#[SensitiveParameter] string $body, int|string $key): array
    {
        try {
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidResponseException($key);
        }
        if (!is_array($response)) {
            throw new InvalidResponseException($key);
        }
        if (($response['status'] ?? null) === 'error') {
            $error = $response['error'] ?? [];
            if (!is_array($error)) {
                throw new InvalidResponseException($key);
            }
            $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : 0;
            $message = $error['message'] ?? '';
            $retryable = in_array($message, ['REQUEST_LIMIT_EXCEEDED', 'SOURCE_NOT_AVAILABLE'], true);
            throw new RequestException($key, $retryable, code: $code);
        }
        if (($response['status'] ?? null) !== 'ok' || !array_key_exists('data', $response)) {
            throw new InvalidResponseException($key);
        }
        return $response;
    }
}
