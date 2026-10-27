<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;

/** GET requests of Salon: the route's permission somewhere (403), then at the branch or company asked for. Lists are narrowed to the reader's places by the controller. Every filter is optional. */
class ViewRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'service_id' => ['sometimes', 'nullable', 'uuid'],
            'batch_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'source' => ['sometimes', 'nullable', 'string', 'in:staff,walk_in,online,device'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:20'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'between:1,14'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'service_ids' => ['sometimes', 'array', 'max:10'],
            'service_ids.*' => ['uuid'],
            'unbatched' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
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
