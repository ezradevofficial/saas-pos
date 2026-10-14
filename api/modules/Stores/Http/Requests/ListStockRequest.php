<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Stores\Http\Lists\StockList;

/**
 * GET stores/stock (STR-11): `stores.ledger.view`; the own ledger's stock
 * on hand at the reader's stores; `?item=`, `?search=` (item code or
 * name).
 */
class ListStockRequest extends FormRequest
{
    use ListsStores;

    public function list(): ListDefinition
    {
        return new StockList;
    }

    protected function viewPermission(): string
    {
        return 'stores.ledger.view';
    }
}
