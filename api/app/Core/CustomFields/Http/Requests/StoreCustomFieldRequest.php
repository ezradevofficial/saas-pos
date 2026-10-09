<?php

namespace App\Core\CustomFields\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** CF-01, CF-02: a new custom field of a registered entity (`core.custom_field.manage`). */
class StoreCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CustomFieldAccessRules::manages($this->user());
    }

    public function rules(): array
    {
        return CustomFieldRules::rules(updating: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => CustomFieldRules::validate($validator, $validator->errors()->isEmpty() ? $validator->validated() : [], null)];
    }

    public function attributes(): array
    {
        return CustomFieldRules::attributeNames();
    }

    public function messages(): array
    {
        return CustomFieldRules::messages();
    }
}
