<?php

namespace Modules\Manufacturing\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Manufacturing\Orders\Orders;
use Modules\Manufacturing\Orders\OrderType;

/**
 * WF-10, WF-11, MFG-11: a production order's release flow approved,
 * rejected or was cancelled. Queued after the deciding transaction commits
 * and run in the flow's tenant; only the order's own (latest) flow settles
 * it.
 */
class SettleOrder implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
        private readonly Orders $orders,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return $event->documentType === OrderType::KEY;
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== OrderType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $event instanceof WorkflowCompleted
                ? $this->orders->completed($event->documentId, $event->outcome, $event->userId)
                : $this->orders->flowCancelled($event->documentId);
        });
    }
}
