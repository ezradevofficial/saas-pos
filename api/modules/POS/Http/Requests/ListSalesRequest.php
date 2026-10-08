<?php

namespace Modules\POS\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\POS\Http\Lists\SaleList;
use Modules\POS\Models\Sale;

/** GET pos/sales: `pos.sale.view`; `?search=` matches the receipt number (POS-12). */
class ListSalesRequest extends FormRequest
{
    use ListsPosRecords;

    public function list(): ListDefinition
    {
        return new SaleList;
    }

    protected function viewPermission(): string
    {
        return 'pos.sale.view';
    }

    protected function statuses(): array
    {
        return Sale::STATUSES;
    }
}
