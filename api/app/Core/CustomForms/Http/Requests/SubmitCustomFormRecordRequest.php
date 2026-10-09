<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;

/** CF-04: submit a draft (to its flow, when its type has one), by whoever may edit it. */
class SubmitCustomFormRecordRequest extends CustomFormRecordRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->edit($this->user(), $this->record());
    }
}
