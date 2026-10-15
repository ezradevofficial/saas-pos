<?php

namespace Modules\HR\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\HR\Leave\LeaveRequests;
use Modules\HR\Leave\LeaveRequestType;
use Modules\HR\Rosters\ShiftSwaps;
use Modules\HR\Rosters\ShiftSwapType;

/**
 * WF-10, WF-11, HR-14, HR-19: a leave request's or shift swap's flow
 * approved, rejected or was cancelled. Queued after the deciding
 * transaction commits and run in the flow's tenant; only the document's
 * own (latest) flow settles it.
 */
class SettleHrDocument implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public const TYPES = [LeaveRequestType::KEY, ShiftSwapType::KEY];

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

            $settler = $event->documentType === LeaveRequestType::KEY ? app(LeaveRequests::class) : app(ShiftSwaps::class);

            $event instanceof WorkflowCompleted
                ? $settler->completed($event->documentId, $event->outcome, $event->userId)
                : $settler->cancelled($event->documentId);
        });
    }
}
