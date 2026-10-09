<?php

namespace App\Core\CustomFields\Http\Requests;

use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\CustomFieldTypes;
use App\Core\CustomFields\Http\Lists\CustomFieldList;
use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CF-01: list custom field definitions (`core.custom_field.view` or
 * manage): `?entity=`, `?type=`, `?search=` (label or key), `?status`,
 * `?per_page`, `?sort` and an export (`?format`, `?columns[]`; CustomFieldList).
 */
class ListCustomFieldsRequest extends FormRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new CustomFieldList;
    }

    public function authorize(): bool
    {
        return CustomFieldAccessRules::views($this->user());
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'entity' => ['sometimes', 'string', Rule::in(app(CustomFieldEntities::class)->keys())],
            'type' => ['sometimes', 'string', Rule::in(CustomFieldTypes::ALL)],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
