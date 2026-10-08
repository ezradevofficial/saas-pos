<?php

namespace App\Core\MasterData\CreditLimits\Listeners;

use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\CreditLimits\CreditLimitChangeType;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * WF-10, WF-11: a credit limit change's flow ended (approved: applied to
 * the party; any other outcome: rejected) or was cancelled. M4: queued
 * after the deciding transaction commits and run in the flow's tenant (the
 * event's tenant id), so a failure here never fails the approver's
 * request; ReconcileCreditLimitChanges settles any request it missed.
 */
class SettleCreditLimitChange implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly CreditLimitChanges $changes,
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
    ) {}

    /** Only credit limit changes' flows are queued at all. */
    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return $event->documentType === CreditLimitChangeType::KEY;
    }

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
