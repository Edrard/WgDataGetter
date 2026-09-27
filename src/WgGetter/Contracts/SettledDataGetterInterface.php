<?php

declare(strict_types=1);

namespace edrard\WgGetter\Contracts;

use edrard\WgGetter\RequestOutcome;

interface SettledDataGetterInterface extends DataGetterInterface
{
    /** Complete WG-envelope outcome for every queued request; successful siblings survive failures.
     * @return array<int|string, RequestOutcome>
     */
    public function getEnvelopeOutcomes(?int $concurrency = null): array;
}
