<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use Illuminate\Validation\Validator;

/** MD-02: rename, recolour or move an item category within its scope (`core.item_category.edit`). */
class UpdateItemCategoryRequest extends ItemCategoryRequest
{
    use GuardsFieldRules;

    /** RBAC-05: input refused when its field is hidden or read-only for the user. */
    protected string $fieldRulesResource = 'item_category';

    protected string $ability = 'update';

    public function rules(): array
    {
        return ItemCategoryRules::rules(updating: true);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => ItemCategoryRules::validateCategory(
            $validator, $validator->errors()->isEmpty() ? $validator->validated() : [], $this->category(),
        )];
    }

    public function attributes(): array
    {
        return ItemCategoryRules::attributeNames();
    }

    public function messages(): array
    {
        return ItemCategoryRules::messages();
    }
}
