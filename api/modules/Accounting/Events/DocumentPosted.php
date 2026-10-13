<?php

namespace Modules\Accounting\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** ACC-19, ACC-23: an invoice or credit note was posted (after commit); customer documents go to the fiscal queue. */
class DocumentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $documentId,
        public readonly string $companyId,
        public readonly string $kind,
    ) {}
}
