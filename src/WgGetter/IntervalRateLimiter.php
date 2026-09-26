<?php

declare(strict_types=1);

namespace edrard\WgGetter;

use Closure;
use edrard\WgGetter\Contracts\RateLimiterInterface;
use InvalidArgumentException;

final class IntervalRateLimiter implements RateLimiterInterface
{
    private float $next = 0.0;
    private Closure $clock;
    private Closure $sleep;
    public function __construct(private float $requestsPerSecond = 10.0, ?callable $clock = null, ?callable $sleep = null)
    {
        if ($requestsPerSecond <= 0 || !is_finite($requestsPerSecond)) {
            throw new InvalidArgumentException('Request rate must be finite and positive.');
        }
        $this->clock = $clock === null ? fn (): float => hrtime(true) / 1e9 : Closure::fromCallable($clock);
        $this->sleep = $sleep === null ? static function (float $seconds): void {
            usleep((int) ceil($seconds * 1e6));
        } : Closure::fromCallable($sleep);
    }
    public function acquire(int $requests): void
    {
        if ($requests < 1) {
            throw new InvalidArgumentException('Request count must be positive.');
        }
        $now = ($this->clock)();
        $wait = max(0.0, $this->next - $now);
        if ($wait > 0) {
            ($this->sleep)($wait);
        }
        $this->next = max($this->next, ($this->clock)()) + $requests / $this->requestsPerSecond;
    }
}
