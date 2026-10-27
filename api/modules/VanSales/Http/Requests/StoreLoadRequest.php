<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;
use Modules\VanSales\Models\Van;

/** POST van-sales/loads (VAN-07): `van_sales.load.create` at the van's location. */
class StoreLoadRequest extends VanSalesRequest
{
    public function rules(): array
    {
        return [
            'van_id' => ['required', 'uuid', Rule::exists('vs_vans', 'id')],
            'from_location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'run_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('vs_runs', 'id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'regex:/^\d+(\.\d{1,6})?$/'],
        ];
    }

    protected function target(): ?Scope
    {
        $locationId = $this->filled('van_id') ? Van::query()->whereKey((string) $this->input('van_id'))->value('location_id') : null;

        return $locationId === null ? null : Scope::location($locationId);
    }
}
