<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT inventory/reorder-rules (INV-11): `inventory.reorder.edit` at the
 * location; `{item_id, location_id, reorder_level, reorder_qty?}`; a null
 * level archives the rule.
 */
class UpsertReorderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'inventory.reorder.edit');
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
            'location_id' => InventoryRules::location(),
            'reorder_level' => ['present', 'nullable', 'string', InventoryRules::qty()],
            'reorder_qty' => ['sometimes', 'nullable', 'string', InventoryRules::qty()],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                InventoryRules::at($this->user(), 'inventory.reorder.edit', (string) $this->input('location_id'));
            }
        }];
    }
}
