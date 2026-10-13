<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Lists\ReorderRuleList;

/**
 * GET inventory/reorder-rules (INV-11, INV-19): `inventory.reorder.view`;
 * rules at the reader's locations; `?status=active|archived|all`
 * (active by default, TEN-06), `?flag=low`, `?search=` (item code or name).
 */
class ListReorderRulesRequest extends FormRequest
{
    use ListsInventory;

    public function list(): ListDefinition
    {
        return new ReorderRuleList;
    }

    protected function viewPermission(): string
    {
        return 'inventory.reorder.view';
    }

    protected function extraRules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,archived,all'],
            'flag' => ['sometimes', 'string', 'in:low'],
        ];
    }
}
