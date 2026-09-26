<?php

declare(strict_types=1);

namespace edrard\WgGetter\Contracts;

interface RateLimiterInterface
{
    public function acquire(int $requests): void;
}
