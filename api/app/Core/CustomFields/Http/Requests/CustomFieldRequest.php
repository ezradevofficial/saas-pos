<?php

namespace App\Core\CustomFields\Http\Requests;

use App\Core\CustomFields\CustomFieldDefinition;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CF-01: one custom field definition. Another tenant's is not found
 * (row-level security); seen with `core.custom_field.view` (or manage).
 */
class CustomFieldRequest extends FormRequest
{
    /** Whether the request changes the definition (`core.custom_field.manage` at tenant scope). */
    protected bool $manages = false;

    public function authorize(): bool
    {
        return $this->manages ? CustomFieldAccessRules::manages($this->user()) : CustomFieldAccessRules::views($this->user());
    }

    public function rules(): array
    {
        return [];
    }

    public function definition(): CustomFieldDefinition
    {
        return $this->route('custom_field');
    }
}
