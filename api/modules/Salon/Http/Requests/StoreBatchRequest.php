<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/commission-batches (SAL-23): `salon.commission.build` at the company. */
class StoreBatchRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id')],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atBranch($this->input('branch_id')) ?? $this->atCompany($this->input('company_id'));
    }
}
