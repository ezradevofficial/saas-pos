<?php

namespace Modules\Ecommerce\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ecommerce\Orders\Returns;
use Modules\Ecommerce\Orders\ReturnType;

/**
 * WF-10, WF-11, review H1: a return's approval flow ended (approved or
 * rejected) or was cancelled. Queued after the deciding transaction
 * commits, run in the flow's tenant; only the return's own (latest) flow
 * settles it.
 */
class SettleReturn implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

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
        return $event->documentType === ReturnType::KEY;
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== ReturnType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $approved = $event instanceof WorkflowCompleted && $event->outcome === 'approved';
            app(Returns::class)->decided($event->documentId, $approved, $event instanceof WorkflowCompleted ? $event->userId : null);
        });
    }
}
