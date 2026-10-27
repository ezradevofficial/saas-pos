<?php

namespace Modules\Pharmacy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PHA-22, PHA-30: a recall of one batch of an item was issued. After commit. */
class RecallIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<string> $lotIds the lots it blocked */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $recallId,
        public readonly string $itemId,
        public readonly string $batchNumber,
        public readonly array $lotIds,
    ) {}
}
