<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** CF-04: a new custom form type (`core.custom_form_type.manage` at tenant scope). */
class StoreCustomFormTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->manages($this->user());
    }

    public function rules(): array
    {
        return CustomFormTypeRules::rules(updating: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => CustomFormTypeRules::validate($validator, $validator->errors()->isEmpty() ? $validator->validated() : [], null)];
    }

    public function attributes(): array
    {
        return CustomFormTypeRules::attributeNames();
    }

    public function messages(): array
    {
        return ['key.regex' => __('core.custom_form_type.key_invalid')];
    }
}
