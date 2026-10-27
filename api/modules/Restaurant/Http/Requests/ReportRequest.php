<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;

/** GET restaurant/reports/summary (REST-18): `restaurant.report.view`; places beyond the reader's are left out. */
class ReportRequest extends RestaurantRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    protected function target(): ?Scope
    {
        if ($this->filled('location_id')) {
            return Scope::location((string) $this->input('location_id'));
        }

        if ($this->filled('branch_id')) {
            return Scope::branch((string) $this->input('branch_id'));
        }

        return $this->filled('company_id') ? Scope::company((string) $this->input('company_id')) : null;
    }
}
