<?php

namespace App\Core\Workflow\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document's flow reached its end (WF-10). `outcome` is the end node's
 * (approved, rejected, completed ...): the owning module applies it, e.g.
 * a credit limit change is applied on `approved`. Dispatched after commit.
 */
class WorkflowCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $workflowId,
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $outcome,
        public readonly ?string $userId,
    ) {}
}
