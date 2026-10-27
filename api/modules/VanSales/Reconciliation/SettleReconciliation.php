<?php

namespace Modules\VanSales\Reconciliation;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * WF-10, WF-11, VAN-21, VAN-22: a count's flow ended (approved ones write
 * the stock off) or was cancelled. Queued after the deciding transaction
 * commits and run in the flow's tenant, so a refused posting never fails the
 * approver's request; only the document's own (latest) flow settles it.
 */
class SettleReconciliation implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
        private readonly Counts $counts,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return $event->documentType === ReconciliationType::KEY;
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== ReconciliationType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $event instanceof WorkflowCompleted
                ? $this->counts->completed($event->documentId, $event->outcome, $event->userId)
                : $this->counts->cancelled($event->documentId);
        });
    }
}
