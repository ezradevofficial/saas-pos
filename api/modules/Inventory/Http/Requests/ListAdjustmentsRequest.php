<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Lists\AdjustmentList;
use Modules\Inventory\Models\Adjustment;

/**
 * GET inventory/adjustments (INV-10, INV-19): `inventory.adjustment.view`;
 * adjustments at the reader's locations; `?status=`, `?from=`, `?to=`,
 * `?search=` (number or note).
 */
class ListAdjustmentsRequest extends FormRequest
{
    use ListsInventory;

    public function list(): ListDefinition
    {
        return new AdjustmentList;
    }

    protected function viewPermission(): string
    {
        return 'inventory.adjustment.view';
    }

    protected function statuses(): array
    {
        return Adjustment::STATUSES;
    }
}
