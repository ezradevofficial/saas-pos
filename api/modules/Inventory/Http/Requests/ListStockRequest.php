<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Lists\StockList;

/**
 * GET inventory/stock (INV-05, INV-19): `inventory.stock.view`; rows at the
 * reader's locations; `?search=` (item code or name), `?category=` (an
 * item category), `?flag=low` (at or below the reorder level).
 */
class ListStockRequest extends FormRequest
{
    use ListsInventory;

    public function list(): ListDefinition
    {
        return new StockList;
    }

    protected function viewPermission(): string
    {
        return 'inventory.stock.view';
    }

    protected function extraRules(): array
    {
        return [
            'category' => ['sometimes', 'uuid', Rule::exists('item_categories', 'id')],
            'flag' => ['sometimes', 'string', 'in:low'],
        ];
    }
}
