<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST van-sales/vans (VAN-02): `van_sales.van.manage` at the branch. */
class StoreVanRequest extends VanSalesRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:30'],
            'rep_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'device_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('devices', 'id')],
            'location_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('locations', 'id')],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->filled('branch_id') ? Scope::branch((string) $this->input('branch_id')) : null;
    }
}
