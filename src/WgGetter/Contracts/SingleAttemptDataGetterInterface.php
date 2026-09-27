<?php

declare(strict_types=1);

namespace edrard\WgGetter\Contracts;

use edrard\WgGetter\RequestOutcome;

/** Collection policies own retries; this capability executes each queued URL once. */
interface SingleAttemptDataGetterInterface extends SettledDataGetterInterface
{
    /** @return array<int|string, RequestOutcome> */
    public function getEnvelopeOutcomesOnce(?int $concurrency = null): array;
}
