<?php

namespace App\Core\CustomForms\Listeners;

use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomForms;
use App\Core\CustomForms\CustomFormType;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Runtime\WorkflowEngine;

/**
 * WF-10, WF-11: a custom form record's flow ended (approved or rejected)
 * or was cancelled; the record follows, in the flow's tenant. Runs after
 * the deciding transaction commits (the events are dispatched after
 * commit); only the record's own (latest) flow settles it.
 */
class SettleCustomFormRecord
{
    public function __construct(
        private readonly CustomForms $forms,
        private readonly TenantContext $tenants,
        private readonly WorkflowEngine $engine,
    ) {}

    public function handle(WorkflowCompleted|WorkflowCancelled $event): void
    {
        if (! str_starts_with($event->documentType, CustomFormType::DOCUMENT_PREFIX)) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->engine->current($event->documentType, $event->documentId)?->id !== $event->workflowId) {
                return;
            }

            $record = CustomFormRecord::query()->find($event->documentId);

            if ($record === null) {
                return;
            }

            $event instanceof WorkflowCompleted ? $this->forms->completed($record, $event->outcome) : $this->forms->flowCancelled($record);
        });
    }
}
