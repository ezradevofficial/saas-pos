<?php

namespace Modules\Accounting\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** ACC-23: a journal was posted (after commit), with the source it came from, if any. */
class JournalPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $journalId,
        public readonly string $companyId,
        public readonly string $sourceType,
        public readonly ?string $sourceId,
    ) {}
}
