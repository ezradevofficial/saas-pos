<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Lists\ListDefinition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Stores\Http\Lists\ReturnList;

/**
 * GET stores/returns (STR-06, STR-18): `stores.return.view`; returns at
 * the reader's stores; `?department=`, `?cost_centre=`, `?item=`,
 * `?from=`, `?to=`, `?search=` (number or note).
 */
class ListReturnsRequest extends FormRequest
{
    use ListsStores;

    public function list(): ListDefinition
    {
        return new ReturnList;
    }

    protected function viewPermission(): string
    {
        return 'stores.return.view';
    }

    protected function documentsWithItem(string $itemId): Closure
    {
        return fn ($q) => $q->select('return_id')->from('stores_return_lines')->where('item_id', $itemId);
    }
}
