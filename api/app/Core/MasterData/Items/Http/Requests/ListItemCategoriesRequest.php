<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Http\Requests\ListsArchivable;
use App\Core\MasterData\Items\ItemCategoryPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** MD-02: item categories the user can view (`core.item_category.view` anywhere); `?status`, `?per_page`. */
class ListItemCategoriesRequest extends FormRequest
{
    use ListsArchivable;

    public function authorize(): bool
    {
        return app(ItemCategoryPolicy::class)->viewAny($this->user());
    }

    public function rules(): array
    {
        return $this->listRules();
    }
}
