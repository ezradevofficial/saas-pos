<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Lists\RfqList;
use Modules\Procurement\Models\Rfq;

/** GET procurement/rfqs (PRC-03, PRC-21): `procurement.rfq.view`; the list contract of ListsProcurement; `?search=` on number or title. */
class ListRfqsRequest extends FormRequest
{
    use ListsProcurement;

    public function list(): ListDefinition
    {
        return new RfqList;
    }

    protected function viewPermission(): string
    {
        return 'procurement.rfq.view';
    }

    protected function statuses(): array
    {
        return Rfq::STATUSES;
    }

    protected function hasParty(): bool
    {
        return false;
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like);
        $query->orWhere($query->qualifyColumn('title'), 'ilike', $like);
    }
}
