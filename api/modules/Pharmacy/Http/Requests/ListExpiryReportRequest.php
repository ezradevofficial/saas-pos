<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Modules\Pharmacy\Http\Lists\ExpiryList;
use Modules\Pharmacy\Http\StockQuery;

/**
 * GET pharmacy/reports/expiry (PHA-10, PHA-24): `pharmacy.report.view`; lots
 * with stock that expired or expire within `?days=` (default: each
 * company's near-expiry window) at the reader's locations, earliest first.
 */
class ListExpiryReportRequest extends ListLotsRequest
{
    public function list(): ListDefinition
    {
        return new ExpiryList;
    }

    protected function viewPermission(): string
    {
        return 'pharmacy.report.view';
    }

    protected function extraRules(): array
    {
        return [...parent::extraRules(), 'days' => ['sometimes', 'integer', 'between:0,3650']];
    }

    public function builder(): Builder
    {
        $query = parent::builder();

        return StockQuery::expiringWithin($query, $this->filled('days') ? (int) $this->validated('days') : null);
    }
}
