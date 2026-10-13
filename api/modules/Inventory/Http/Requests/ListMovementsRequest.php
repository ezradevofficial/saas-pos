<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Lists\MovementList;
use Modules\Inventory\Models\Movement;

/**
 * GET inventory/movements and inventory/items/{item}/movements (INV-04,
 * INV-19): `inventory.stock.view`; movements at the reader's locations;
 * `?kind=`, `?item=` (the ledger route only), `?from=`, `?to=`,
 * `?search=` (item code or name, batch number).
 */
class ListMovementsRequest extends FormRequest
{
    use ListsInventory;

    public function list(): ListDefinition
    {
        return new MovementList;
    }

    protected function viewPermission(): string
    {
        return 'inventory.stock.view';
    }

    protected function extraRules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::in(Movement::KINDS)],
            'item' => ['sometimes', 'uuid', Rule::exists('items', 'id')],
        ];
    }
}
