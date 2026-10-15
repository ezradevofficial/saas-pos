<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Lists\RequisitionList;
use Modules\Procurement\Models\Requisition;

/** GET procurement/requisitions (PRC-02, PRC-21): `procurement.requisition.view`; the list contract of ListsProcurement; `?search=` on number or purpose. */
class ListRequisitionsRequest extends FormRequest
{
    use ListsProcurement;

    public function list(): ListDefinition
    {
        return new RequisitionList;
    }

    protected function viewPermission(): string
    {
        return 'procurement.requisition.view';
    }

    protected function statuses(): array
    {
        return Requisition::STATUSES;
    }

    protected function locationColumn(): ?string
    {
        return 'location_id';
    }

    protected function hasParty(): bool
    {
        return false;
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like);
        $query->orWhere($query->qualifyColumn('purpose'), 'ilike', $like);
    }
}
