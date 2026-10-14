<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Lists\ListDefinition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Stores\Http\Lists\RequisitionList;
use Modules\Stores\Models\Requisition;

/**
 * GET stores/requisitions (STR-02, STR-18): `stores.requisition.view`;
 * requisitions at the reader's stores; `?status=`, `?department=`,
 * `?cost_centre=`, `?item=`, `?from=`, `?to=`, `?search=` (number or note).
 */
class ListRequisitionsRequest extends FormRequest
{
    use ListsStores;

    public function list(): ListDefinition
    {
        return new RequisitionList;
    }

    protected function viewPermission(): string
    {
        return 'stores.requisition.view';
    }

    protected function statuses(): array
    {
        return Requisition::STATUSES;
    }

    protected function documentsWithItem(string $itemId): Closure
    {
        return fn ($q) => $q->select('requisition_id')->from('stores_requisition_lines')->where('item_id', $itemId);
    }
}
