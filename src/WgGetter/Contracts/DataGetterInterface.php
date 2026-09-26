<?php

declare(strict_types=1);

namespace edrard\WgGetter\Contracts;

interface DataGetterInterface
{
    /**
     * @param array<array-key, mixed> $urls
     */
    public function setUrls(array $urls): void;
    public function cleanUrls(): void;
    /**
     * @return array<array-key, mixed>
     */
    public function getData(?callable $function = null, bool $instead = false): array;
    /**
     * Full WG envelopes, including pagination metadata.
     * @return array<array-key, mixed>
     */
    public function getEnvelopes(): array;
}
