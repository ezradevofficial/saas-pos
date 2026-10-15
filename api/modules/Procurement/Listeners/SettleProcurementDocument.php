<?php

namespace Modules\Procurement\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Documents\Orders;
use Modules\Procurement\Documents\OrderType;
use Modules\Procurement\Documents\Requisitions;
use Modules\Procurement\Documents\RequisitionType;

/**
 * WF-10, WF-11: a requisition's or PO's flow ended (approved or rejected)
 * or was cancelled. Queued after the deciding transaction commits and run
 * in the flow's tenant; only the document's own (latest) flow settles it.
 */
class SettleProcurementDocument implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    private const TYPES = [RequisitionType::KEY, OrderType::KEY];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return in_array($event->documentType, self::TYPES, true);
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if (! in_array($event->documentType, self::TYPES, true)) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $service = $event->documentType === RequisitionType::KEY ? app(Requisitions::class) : app(Orders::class);

            $event instanceof WorkflowCompleted
                ? $service->completed($event->documentId, $event->outcome, $event->userId)
                : $service->cancelled($event->documentId);
        });
    }
}
