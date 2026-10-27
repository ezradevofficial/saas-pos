<?php

namespace Modules\FuelStation\Approvals;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\FuelStation\Shifts\Settlements;
use Modules\FuelStation\Tanks\Reconciliations;

/**
 * WF-10, WF-11, FUEL-14, FUEL-26: a variance flow ended (approved ones are
 * posted) or was cancelled. Queued after the deciding transaction commits
 * and run in the flow's tenant, so a refused posting never fails the
 * approver's request; only the document's own (latest) flow settles it.
 */
class SettleVariance implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
        private readonly Settlements $settlements,
        private readonly Reconciliations $reconciliations,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return in_array($event->documentType, [ShiftVarianceType::KEY, TankVarianceType::KEY], true);
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if (! in_array($event->documentType, [ShiftVarianceType::KEY, TankVarianceType::KEY], true)) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $shift = $event->documentType === ShiftVarianceType::KEY;

            // FUEL-14: a cancelled approval leaves the document waiting to be submitted again, never pending for ever.
            if ($event instanceof WorkflowCancelled) {
                $shift ? $this->settlements->cancelled($event->documentId) : $this->reconciliations->cancelled($event->documentId);

                return;
            }

            $shift
                ? $this->settlements->completed($event->documentId, $event->outcome, $event->userId)
                : $this->reconciliations->completed($event->documentId, $event->outcome, $event->userId);
        });
    }
}
