<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST van-sales/routes (VAN-03): `van_sales.route.manage` at the branch. */
class StoreRouteRequest extends VanSalesRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'van_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('vs_vans', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'visit_days' => ['required', 'array', 'max:7'],
            'visit_days.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->filled('branch_id') ? Scope::branch((string) $this->input('branch_id')) : null;
    }
}
