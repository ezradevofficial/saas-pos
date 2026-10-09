<?php

namespace App\Core\CustomFields\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * CF-02: change a custom field's settings (`core.custom_field.manage`).
 * The entity, key and type stay as created.
 */
class UpdateCustomFieldRequest extends CustomFieldRequest
{
    protected bool $manages = true;

    public function rules(): array
    {
        return CustomFieldRules::rules(updating: true);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => CustomFieldRules::validate($validator, $validator->errors()->isEmpty() ? $validator->validated() : [], $this->definition())];
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
