<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Lists\InvoiceList;
use Modules\Procurement\Models\Invoice;

/** GET procurement/invoices (PRC-15, PRC-21): `procurement.invoice.view`; the list contract of ListsProcurement; `?search=` on number or supplier_reference. */
class ListInvoicesRequest extends FormRequest
{
    use ListsProcurement;

    public function list(): ListDefinition
    {
        return new InvoiceList;
    }

    protected function viewPermission(): string
    {
        return 'procurement.invoice.view';
    }

    protected function statuses(): array
    {
        return Invoice::STATUSES;
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like);
        $query->orWhere($query->qualifyColumn('supplier_reference'), 'ilike', $like);
    }
}
