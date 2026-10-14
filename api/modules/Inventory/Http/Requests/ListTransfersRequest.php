<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Lists\TransferList;
use Modules\Inventory\Models\Transfer;

/**
 * GET inventory/transfers (INV-08, INV-19): `inventory.transfer.view`;
 * transfers from or to the reader's locations; `?status=`, `?location=`
 * (either end), `?from=`, `?to=` (created), `?search=` (number).
 */
class ListTransfersRequest extends FormRequest
{
    use ListsInventory;

    public function list(): ListDefinition
    {
        return new TransferList;
    }

    protected function viewPermission(): string
    {
        return 'inventory.transfer.view';
    }

    protected function statuses(): array
    {
        return Transfer::STATUSES;
    }
}
