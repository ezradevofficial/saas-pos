<?php

namespace Modules\FixedAssets\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\FixedAssets\Documents\Disposals;
use Modules\FixedAssets\Documents\DisposalType;
use Modules\FixedAssets\Documents\Transfers;
use Modules\FixedAssets\Documents\TransferType;

/**
 * WF-10, WF-11, FA-14, FA-17: a transfer's or disposal's flow approved,
 * rejected or was cancelled. Queued after the deciding transaction commits
 * and run in the flow's tenant; only the document's own (latest) flow
 * settles it.
 */
class SettleFixedAssetDocument implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
        private readonly Transfers $transfers,
        private readonly Disposals $disposals,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return in_array($event->documentType, [TransferType::KEY, DisposalType::KEY], true);
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $documents = $event->documentType === TransferType::KEY ? $this->transfers : $this->disposals;

            $event instanceof WorkflowCompleted
                ? $documents->completed($event->documentId, $event->outcome, $event->userId)
                : $documents->cancelled($event->documentId);
        });
    }
}
