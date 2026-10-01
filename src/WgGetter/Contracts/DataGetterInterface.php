<?php

declare(strict_types=1);

namespace edrard\WgGetter\Contracts;

use edrard\WgGetter\FetchResult;

interface DataGetterInterface
{
    /**
     * Atomically append requests, preserving keys and insertion order.
     * Invalid requests or duplicate keys must leave the existing queue unchanged.
     * Implementations must reject queue changes while fetching.
     * @param array<array-key, mixed> $urls
     * @throws \InvalidArgumentException For invalid requests or duplicate keys.
     * @throws \LogicException When called during fetching.
     */
    public function setUrls(array $urls): void;
    /** Discard pending requests; reject changes while fetching. */
    public function cleanUrls(): void;
    /**
     * Execute the queued URLs together and return one final result per key, in input order.
     * Consume the queue even if infrastructure code throws; an empty queue returns [].
     * Recursive fetching must be rejected. HTTP failures are results, not exceptions.
     * @return array<int|string, FetchResult>
     * @throws \LogicException For recursive fetching.
     */
    public function getData(): array;
}
