<?php

namespace Modules\HR\Http\Requests;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/** GET hr/leave-balances (HR-15): balances of the employees at the reader's places, per type, at `?as_of=`. */
class LeaveBalancesRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'hr.leave.view');
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'department' => ['sometimes', 'uuid', Rule::exists('departments', 'id')],
            'employee' => ['sometimes', 'uuid', Rule::exists('hr_employees', 'id')],
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
