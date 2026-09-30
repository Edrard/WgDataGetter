<?php

declare(strict_types=1);

namespace edrard\WgGetter;

use InvalidArgumentException;

final readonly class RetryPolicy
{
    public function __construct(public int $maxAttempts = 3, public float $baseDelay = 5.0, public float $maxDelay = 30.0)
    {
        if ($maxAttempts < 1 || $baseDelay < 0 || $maxDelay < $baseDelay || !is_finite($baseDelay) || !is_finite($maxDelay)) {
            throw new InvalidArgumentException('Invalid retry policy.');
        }
    }
    /** $attempt is the number of the attempt that just failed (first attempt = 1). */
    public function delay(int $attempt, ?float $retryAfter = null): float
    {
        return min($this->maxDelay, max($this->baseDelay * (2 ** min(30, $attempt - 1)), $retryAfter ?? 0.0));
    }
}
