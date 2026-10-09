<?php

namespace Modules\POS\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\POS\Http\Lists\SaleList;
use Modules\POS\Models\Sale;

/**
 * GET pos/sales: `pos.sale.view`; `?search=` matches the receipt number
 * (POS-12); `?flagged=1|0` sales with or without flags, `?flag=<code>`
 * sales carrying that flag, `?reviewed=1|0` flagged sales acknowledged or
 * not (M3).
 */
class ListSalesRequest extends FormRequest
{
    use ListsPosRecords {
        rules as listRules;
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'flagged' => ['sometimes', 'boolean'],
            'flag' => ['sometimes', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'reviewed' => ['sometimes', 'boolean'],
        ];
    }

    public function list(): ListDefinition
    {
        return new SaleList;
    }

    protected function viewPermission(): string
    {
        return 'pos.sale.view';
    }

    protected function statuses(): array
    {
        return Sale::STATUSES;
    }
}
