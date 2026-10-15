<?php

namespace Modules\Payroll\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Payroll\Runs\Runs;
use Modules\Payroll\Runs\RunType;

/**
 * WF-10, WF-11, PAY-16: a run's flow approved or rejected it, or was
 * cancelled. Queued after the deciding transaction commits and run in the
 * flow's tenant; only the run's own (latest) flow settles it.
 */
class SettleRun implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
        private readonly Runs $runs,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return $event->documentType === RunType::KEY;
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== RunType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current(RunType::KEY, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $event instanceof WorkflowCompleted
                ? $this->runs->completed($event->documentId, $event->outcome, $event->userId)
                : $this->runs->flowCancelled($event->documentId);
        });
    }
}
