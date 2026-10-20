<?php

namespace Tests\Support\Webhooks;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** HOOK-01 tests: an internal domain event of the POS module that only the webhook dispatcher listens to. */
class ThingHappened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $thingId,
        public readonly string $companyId,
        public readonly ?string $branchId = null,
        public readonly ?string $locationId = null,
        public readonly string $note = 'a note',
        public readonly string $amountMinor = '12345',
    ) {}
}
