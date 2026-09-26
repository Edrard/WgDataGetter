<?php

declare(strict_types=1);

namespace edrard\Tests\WgDataGetter\Fixtures;

use edrard\WgGetter\Contracts\RateLimiterInterface;

final class RecordingLimiter implements RateLimiterInterface
{
    public array $counts = [];
    public function acquire(int $requests): void
    {
        $this->counts[] = $requests;
    }
}
