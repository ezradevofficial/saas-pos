<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;

/** CF-04, TEN-06: archive or restore a record (`core.custom_form.edit` at its place); never deleted. */
class ArchiveCustomFormRecordRequest extends CustomFormRecordRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->manageRecord($this->user(), $this->record());
    }
}
