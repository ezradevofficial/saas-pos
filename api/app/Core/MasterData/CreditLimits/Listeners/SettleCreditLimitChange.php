<?php

namespace App\Core\MasterData\CreditLimits\Listeners;

use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\CreditLimits\CreditLimitChangeType;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;

/**
 * WF-10, WF-11: a credit limit change's flow ended (approved: applied to
 * the party; any other outcome: rejected) or was cancelled. Runs right
 * after the deciding transaction commits, in the flow's tenant, so the
 * approver sees the party updated at once.
 */
class SettleCreditLimitChange
{
    public function __construct(
        private readonly CreditLimitChanges $changes,
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
    ) {}

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== CreditLimitChangeType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            // L3: only the request's own (latest) flow settles it.
            if ($this->engine->current(CreditLimitChangeType::KEY, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $event instanceof WorkflowCompleted
                ? $this->changes->completed($event->documentId, $event->outcome, $event->userId)
                : $this->changes->cancelled($event->documentId);
        });
    }
}
