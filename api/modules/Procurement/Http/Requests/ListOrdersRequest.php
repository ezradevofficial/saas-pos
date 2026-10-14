<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Lists\OrderList;
use Modules\Procurement\Models\Order;

/** GET procurement/orders (PRC-13, PRC-21): `procurement.po.view`; the list contract of ListsProcurement; `?search=` on number or terms. */
class ListOrdersRequest extends FormRequest
{
    use ListsProcurement;

    public function list(): ListDefinition
    {
        return new OrderList;
    }

    protected function viewPermission(): string
    {
        return 'procurement.po.view';
    }

    protected function statuses(): array
    {
        return Order::STATUSES;
    }

    protected function locationColumn(): ?string
    {
        return 'location_id';
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like);
        $query->orWhere($query->qualifyColumn('terms'), 'ilike', $like);
    }
}
