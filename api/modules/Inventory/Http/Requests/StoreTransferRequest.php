<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST inventory/transfers (INV-08): `inventory.transfer.create` at the
 * source location; `{from_location_id, to_location_id, note?, lines:
 * [{item_id, uom_id?, qty, batch_id?, serials?}]}`. Ids of another tenant
 * do not exist (422); the stock checks follow (StockInput).
 */
class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'inventory.transfer.create');
    }

    public function rules(): array
    {
        return [
            'from_location_id' => InventoryRules::location(),
            'to_location_id' => InventoryRules::location(),
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            ...InventoryRules::line('lines.*.'),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                InventoryRules::at($this->user(), 'inventory.transfer.create', (string) $this->input('from_location_id'));
            }
        }];
    }
}
