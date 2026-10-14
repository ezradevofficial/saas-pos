<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Lists\TenderList;
use Modules\Procurement\Models\Tender;

/** GET procurement/tenders (PRC-06, PRC-21): `procurement.tender.view`; the list contract of ListsProcurement; `?search=` on number or title. */
class ListTendersRequest extends FormRequest
{
    use ListsProcurement;

    public function list(): ListDefinition
    {
        return new TenderList;
    }

    protected function viewPermission(): string
    {
        return 'procurement.tender.view';
    }

    protected function statuses(): array
    {
        return Tender::STATUSES;
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
