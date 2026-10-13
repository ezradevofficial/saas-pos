<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Billing\Http\Lists\BillingInvoiceList;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * GET subscription/invoices (`core.subscription.view`): `?status=` open,
 * paid, void or all (default); `?search=` (number); sort, page and export
 * (BillingInvoiceList, EXP-01).
 */
class ListBillingInvoicesRequest extends SubscriptionRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new BillingInvoiceList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in(['all', ...BillingInvoice::STATUSES])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
