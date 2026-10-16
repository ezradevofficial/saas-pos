<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Manufacturing\Http\Lists\OrderList;
use Modules\Manufacturing\Models\Order;

/**
 * GET manufacturing/orders (MFG-10 to MFG-14, MFG-27):
 * `manufacturing.order.view`; orders at the reader's locations;
 * `?status=`, `?item=`, `?company=`, `?branch=`, `?location=`, `?from=`
 * and `?to=` (created), `?search=` (number, note).
 */
class ListOrdersRequest extends FormRequest
{
    use ListsManufacturing;

    public function list(): ListDefinition
    {
        return new OrderList;
    }

    protected function viewPermission(): string
    {
        return 'manufacturing.order.view';
    }

    protected function statuses(): array
    {
        return Order::STATUSES;
    }
}
