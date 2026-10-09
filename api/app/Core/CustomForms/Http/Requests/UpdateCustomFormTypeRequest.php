<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** CF-04: change a custom form type's settings; its key never changes (`core.custom_form_type.manage`). */
class UpdateCustomFormTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->manages($this->user());
    }

    public function rules(): array
    {
        return CustomFormTypeRules::rules(updating: true);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => CustomFormTypeRules::validate($validator, $validator->errors()->isEmpty() ? $validator->validated() : [], $this->type())];
    }

    public function type(): CustomFormType
    {
        return $this->route('custom_form_type');
    }

    public function attributes(): array
    {
        return CustomFormTypeRules::attributeNames();
    }
}
