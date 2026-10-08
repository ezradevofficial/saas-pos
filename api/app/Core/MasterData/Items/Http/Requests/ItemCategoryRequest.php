<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemCategoryPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-02: one item category. Not reached with any item category
 * permission: 404 (RBAC-04); reached without this request's ability: 403.
 */
class ItemCategoryRequest extends FormRequest
{
    /** The ItemCategoryPolicy ability: view, update, archive. */
    protected string $ability = 'view';

    public function authorize(): bool
    {
        $policy = app(ItemCategoryPolicy::class);

        abort_unless($policy->reach($this->user(), $this->category()), 404);

        return $policy->{$this->ability}($this->user(), $this->category());
    }

    public function rules(): array
    {
        return [];
    }

    public function category(): ItemCategory
    {
        return $this->route('item_category');
    }
}
