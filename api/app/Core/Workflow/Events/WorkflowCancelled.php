<?php

namespace App\Core\Workflow\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A document's flow was cancelled with a reason (WF-11). Dispatched after commit. */
class WorkflowCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $workflowId,
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $reason,
        public readonly ?string $userId,
    ) {}
}
