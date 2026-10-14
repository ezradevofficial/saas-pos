<?php

namespace Modules\HR\Http\Requests;

/** GET hr/checklist-templates (HR-28): rows of the companies the reader reads with `hr.checklist.view`. */
class ListChecklistTemplatesRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.checklist.view';
    }

    protected function archivable(): bool
    {
        return true;
    }
}
