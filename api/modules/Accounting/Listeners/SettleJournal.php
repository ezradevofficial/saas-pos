<?php

namespace Modules\Accounting\Listeners;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Accounting\Ledger\JournalType;
use Modules\Accounting\Ledger\ManualJournals;

/**
 * ACC-04, WF-10, WF-11: a journal's flow ended (approved: posted;
 * rejected: back to its author) or was cancelled (back to draft). Queued
 * after the deciding transaction commits and run in the flow's tenant, so
 * a refused posting never fails the approver's request; the refusal stays
 * on the journal (`post_error`).
 */
class SettleJournal implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly ManualJournals $journals,
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(WorkflowCompleted|WorkflowCancelled $event): bool
    {
        return $event->documentType === JournalType::KEY;
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== JournalType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current(JournalType::KEY, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            if ($event instanceof WorkflowCancelled) {
                $this->journals->flowCancelled($event->documentId);

                return;
            }

            try {
                $this->journals->completed($event->documentId, $event->outcome, $event->userId);
            } catch (ApiException) {
                // Kept on the journal as post_error; retrying would refuse again.
            }
        });
    }
}
