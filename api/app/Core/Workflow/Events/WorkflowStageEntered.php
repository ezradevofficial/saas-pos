<?php

namespace App\Core\Workflow\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document entered a stage or approval (WF-10; AUTO-01 "stage entered").
 * Dispatched after the move commits; listeners enter the tenant themselves
 * (TenantContext::run) when queued.
 */
class WorkflowStageEntered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $workflowId,
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $nodeId,
        public readonly string $nodeType,
        public readonly string $versionId,
        public readonly ?string $userId,
    ) {}
}
