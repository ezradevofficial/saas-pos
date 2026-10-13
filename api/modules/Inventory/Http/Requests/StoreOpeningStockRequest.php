<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST inventory/opening-stock (INV-18), and each row of the opening stock
 * import (IMP-01, DomainRequests): `inventory.opening.create` at the
 * location; `{item_id, location_id, uom_id?, qty, unit_cost, batch_number?,
 * expires_on?, serials?}`.
 */
class StoreOpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'inventory.opening.create');
    }

    public function rules(): array
    {
        return [
            'location_id' => InventoryRules::location(),
            ...InventoryRules::line(''),
            'unit_cost' => ['required', 'string', InventoryRules::UNIT_COST],
            'batch_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                InventoryRules::at($this->user(), 'inventory.opening.create', (string) $this->input('location_id'));
            }
        }];
    }
}
