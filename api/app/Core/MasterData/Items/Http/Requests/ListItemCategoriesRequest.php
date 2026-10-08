<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Items\Http\Lists\ItemCategoryList;
use App\Core\MasterData\Items\ItemCategoryPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-02: item categories the user can view (`core.item_category.view`
 * anywhere); `?status`, `?per_page`, `?search=` (name, unless field rules
 * hide it), `?sort` and an export (`?format`, `?columns[]`;
 * ItemCategoryList, EXP-01).
 */
class ListItemCategoriesRequest extends FormRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new ItemCategoryList;
    }

    public function authorize(): bool
    {
        return app(ItemCategoryPolicy::class)->viewAny($this->user());
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
