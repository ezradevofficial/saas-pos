<?php

namespace Modules\CRM\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\CRM\Quotes\Quotes;
use Modules\CRM\Quotes\QuoteType;

/**
 * WF-10, WF-11, CRM-21: a quote's flow ended (approved or rejected) or was
 * cancelled. Queued after the deciding transaction commits and run in the
 * flow's tenant; only the quote's own (latest) flow settles it.
 */
class SettleQuote implements ShouldQueue
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
        return $event->documentType === QuoteType::KEY;
    }

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if ($event->documentType !== QuoteType::KEY) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $event instanceof WorkflowCompleted
                ? app(Quotes::class)->completed($event->documentId, $event->outcome, $event->userId)
                : app(Quotes::class)->cancelled($event->documentId);
        });
    }
}
