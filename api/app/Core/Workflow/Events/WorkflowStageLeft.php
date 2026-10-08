<?php

namespace App\Core\Workflow\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document left a stage or approval (WF-10; AUTO-01 "stage left").
 * `how`: completed (moved on; `outcome` approved or rejected for an
 * approval), returned, cancelled, or joined (a parallel "any" join closed
 * the branch). Dispatched after commit.
 */
class WorkflowStageLeft implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $workflowId,
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $nodeId,
        public readonly string $nodeType,
        public readonly string $how,
        public readonly ?string $outcome,
        public readonly ?string $userId,
    ) {}
}
