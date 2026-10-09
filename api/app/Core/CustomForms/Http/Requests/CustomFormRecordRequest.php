<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormRecord;
use Illuminate\Foundation\Http\FormRequest;

/** CF-04: one custom form record, for those who see it at its place (RBAC-04). */
class CustomFormRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->view($this->user(), $this->record());
    }

    public function record(): CustomFormRecord
    {
        return $this->route('custom_form_record');
    }

    public function rules(): array
    {
        return [];
    }
}
