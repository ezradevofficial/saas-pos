<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormType;
use Illuminate\Foundation\Http\FormRequest;

/** CF-04: one custom form type, for those who manage types or may use it. */
class ShowCustomFormTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(CustomFormAccess::class);
        /** @var CustomFormType $type */
        $type = $this->route('custom_form_type');

        return $access->manages($this->user()) || $access->anywhere($this->user(), $type, [CustomFormAccess::VIEW, CustomFormAccess::EDIT, CustomFormAccess::CREATE]);
    }

    public function rules(): array
    {
        return [];
    }
}
