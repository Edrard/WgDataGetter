<?php

declare(strict_types=1);

namespace edrard\WgGetter;

final readonly class RequestOutcome
{
    /** @param array<array-key, mixed>|null $envelope */
    public function __construct(#[\SensitiveParameter] private ?array $envelope, public ?RequestFailure $failure, public int $attempts)
    {
        if (($envelope === null) === ($failure === null) || $attempts < 1) {
            throw new \InvalidArgumentException('Invalid request outcome.');
        }
    }
    public function succeeded(): bool
    {
        return $this->failure === null;
    }
    /** @return array<array-key, mixed> */
    public function envelope(): array
    {
        return $this->envelope ?? throw new \LogicException('Request did not succeed.');
    }
    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['succeeded' => $this->succeeded(), 'attempts' => $this->attempts, 'failure' => $this->failure];
    }
}
