<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;

/**
 * GET requests of Restaurant: the route's permission somewhere (403), then
 * at the location or company asked for. Lists are narrowed to the
 * reader's places by the controller. `location_id` and `company_id` are
 * optional filters.
 */
class ViewRequest extends RestaurantRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'station_id' => ['sometimes', 'nullable', 'uuid'],
            'table_id' => ['sometimes', 'nullable', 'uuid'],
            'waiter_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'in:open,closed,cancelled,merged,all,active,new,preparing,ready,served'],
            'kind' => ['sometimes', 'nullable', 'string', 'in:table,tab'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'since' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function target(): ?Scope
    {
        if ($this->filled('location_id')) {
            return Scope::location((string) $this->input('location_id'));
        }

        return $this->filled('company_id') ? Scope::company((string) $this->input('company_id')) : null;
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 50);
    }
}
