<?php

namespace App\Core\Payments\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Payments\Http\Lists\PaymentReceiptList;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Tenancy\Models\Company;
use Illuminate\Validation\Rule;

/**
 * GET companies/{company}/payment-receipts: money received on the
 * company's Till or Paybill (C2B). Receipts belong to the company, not a
 * location, so rows are listed only for users holding `core.payment.view`
 * or `core.payment.match` at the company (or tenant-wide). `?status=`
 * `unmatched` (default), `matched` or `all`; `?search=` (receipt, account
 * reference); `?sort`, `?per_page` and an export (PaymentReceiptList).
 */
class ListPaymentReceiptsRequest extends CompanyResourceRequest
{
    use SortsAndExports;

    protected string $resource = 'payment';

    protected array $readActions = ['view', 'match'];

    public function list(): ListDefinition
    {
        return new PaymentReceiptList($this->route('company'));
    }

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in(['all', 'matched', 'unmatched'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }

    /** Whether the user holds a payment permission at the company itself (or above). */
    public function atCompany(): bool
    {
        return $this->user()->can('core.payment.view', $this->targetCompany()) || $this->user()->can('core.payment.match', $this->targetCompany());
    }
}
