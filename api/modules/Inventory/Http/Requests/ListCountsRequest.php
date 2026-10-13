<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Lists\CountList;
use Modules\Inventory\Models\StockCount;

/**
 * GET inventory/counts (INV-09, INV-19): `inventory.count.view`; counts at
 * the reader's locations; `?status=`, `?from=`, `?to=`, `?search=` (number).
 */
class ListCountsRequest extends FormRequest
{
    use ListsInventory;

    public function list(): ListDefinition
    {
        return new CountList;
    }

    protected function viewPermission(): string
    {
        return 'inventory.count.view';
    }

    protected function statuses(): array
    {
        return StockCount::STATUSES;
    }
}
