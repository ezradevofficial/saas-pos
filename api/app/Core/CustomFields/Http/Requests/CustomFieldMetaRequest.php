<?php

namespace App\Core\CustomFields\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** CF-01: the entities, types, lookup targets and formula language (`core.custom_field.view` or manage). */
class CustomFieldMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CustomFieldAccessRules::views($this->user());
    }

    public function rules(): array
    {
        return [];
    }
}
