<?php

namespace Modules\HR\Http\Requests;

/** POST hr/checklist-templates (HR-28): `hr.checklist.manage` at the company. */
class StoreChecklistTemplateRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.checklist.manage';
    }

    protected function fields(): array
    {
        return ChecklistTemplateRequest::rulesFor(true);
    }
}
