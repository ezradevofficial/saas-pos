<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;

/**
 * GET requests of Van sales: the route's permission somewhere (403), then at
 * the branch or company asked for. Lists are narrowed to the reader's places
 * by the controller. Every filter is optional.
 */
class ViewRequest extends VanSalesRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'van_id' => ['sometimes', 'nullable', 'uuid'],
            'route_id' => ['sometimes', 'nullable', 'uuid'],
            'rep_id' => ['sometimes', 'nullable', 'uuid'],
            'customer_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'review_status' => ['sometimes', 'nullable', 'string', 'in:pending,reviewed,all'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function target(): ?Scope
    {
        if ($this->filled('branch_id')) {
            return Scope::branch((string) $this->input('branch_id'));
        }

        return $this->filled('company_id') ? Scope::company((string) $this->input('company_id')) : null;
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 50);
    }
}
