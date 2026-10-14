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
 * `?search=` (item code or name, batch number), `?source=` (a source document's id).
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
            // Stage 7B: the movements of one source document (Accounting's drill-down from a stock journal).
            // A source of the tenant's own ledger (row-level security): another tenant's id is refused, never echoed.
            'source' => ['sometimes', 'uuid', Rule::exists('inventory_movements', 'source_id')],
        ];
    }
}
