<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;

/** CF-04, WF-11: cancel a draft or a pending record (its flow too), with a reason, by whoever may edit it. */
class CancelCustomFormRecordRequest extends CustomFormRecordRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->edit($this->user(), $this->record());
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
