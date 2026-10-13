<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\StockCount;

/**
 * POST inventory/counts (INV-09): `inventory.count.create` at the location;
 * `{location_id, kind: full|cycle, item_ids?, category_id?, note?}` (a
 * cycle count names items or a category).
 */
class StoreCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'inventory.count.create');
    }

    public function rules(): array
    {
        return [
            'location_id' => InventoryRules::location(),
            'kind' => ['required', 'string', Rule::in([StockCount::FULL, StockCount::CYCLE])],
            'item_ids' => ['sometimes', 'array', 'max:2000'],
            'item_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('items', 'id')],
            'category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('item_categories', 'id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                InventoryRules::at($this->user(), 'inventory.count.create', (string) $this->input('location_id'));
            }
        }];
    }
}
