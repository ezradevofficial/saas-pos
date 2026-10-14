<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Adjustment;

/**
 * POST inventory/adjustments (INV-10): `inventory.adjustment.create` at the
 * location; `{location_id, reason, note?, lines: [{item_id, uom_id?, qty
 * (signed), batch_id?, batch_number?, expires_on?, serials?, unit_cost?}]}`.
 */
class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'inventory.adjustment.create');
    }

    public function rules(): array
    {
        return [
            'location_id' => InventoryRules::location(),
            'reason' => ['required', 'string', Rule::in(Adjustment::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            ...InventoryRules::line('lines.*.', signed: true, receiving: true),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                InventoryRules::at($this->user(), 'inventory.adjustment.create', (string) $this->input('location_id'));
            }
        }];
    }
}
