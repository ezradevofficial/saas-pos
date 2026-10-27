<?php

namespace Modules\Pharmacy\WriteOffs;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * WF-10, WF-11, PHA-12: a write-off's flow ended (approved ones are posted)
 * or was cancelled. Queued after the deciding transaction commits and run in
 * the flow's tenant, so a refused posting never fails the approver's
 * request; only the document's own (latest) flow settles it.
 */
class SettleWriteOff implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
        private readonly WriteOffs $writeOffs,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return $event->documentType === WriteOffType::KEY;
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== WriteOffType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $event instanceof WorkflowCompleted
                ? $this->writeOffs->completed($event->documentId, $event->outcome, $event->userId)
                : $this->writeOffs->cancelled($event->documentId);
        });
    }
}
