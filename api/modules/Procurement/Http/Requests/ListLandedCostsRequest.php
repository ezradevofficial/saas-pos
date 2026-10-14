<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Lists\LandedCostList;
use Modules\Procurement\Models\LandedCost;

/** GET procurement/landed-costs (PRC-17, PRC-21): `procurement.landed_cost.view`; the list contract of ListsProcurement; `?search=` on number or description. */
class ListLandedCostsRequest extends FormRequest
{
    use ListsProcurement;

    public function list(): ListDefinition
    {
        return new LandedCostList;
    }

    protected function viewPermission(): string
    {
        return 'procurement.landed_cost.view';
    }

    protected function statuses(): array
    {
        return LandedCost::STATUSES;
    }

    protected function hasParty(): bool
    {
        return false;
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like);
        $query->orWhere($query->qualifyColumn('description'), 'ilike', $like);
    }
}
