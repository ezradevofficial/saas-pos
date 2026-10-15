<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Lists\ReceiptList;
use Modules\Procurement\Models\Receipt;

/** GET procurement/receipts (PRC-14, PRC-21): `procurement.receipt.view`; the list contract of ListsProcurement; `?search=` on number or delivery_note. */
class ListReceiptsRequest extends FormRequest
{
    use ListsProcurement;

    public function list(): ListDefinition
    {
        return new ReceiptList;
    }

    protected function viewPermission(): string
    {
        return 'procurement.receipt.view';
    }

    protected function statuses(): array
    {
        return Receipt::STATUSES;
    }

    protected function locationColumn(): ?string
    {
        return 'location_id';
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like);
        $query->orWhere($query->qualifyColumn('delivery_note'), 'ilike', $like);
    }
}
