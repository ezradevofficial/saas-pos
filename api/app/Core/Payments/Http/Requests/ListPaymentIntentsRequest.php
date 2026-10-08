<?php

namespace App\Core\Payments\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Payments\Http\Lists\PaymentIntentList;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Tenancy\Models\Company;
use Illuminate\Validation\Rule;

/**
 * GET companies/{company}/payment-intents (`core.payment.view` or
 * `core.payment.match` at the company or beneath it; rows of the
 * locations the user reaches): `?status=` a status, `unverified` or
 * `mismatch` (manual payments), or `all` (default); `?search=` (reference,
 * receipt, account reference); `?sort`, `?per_page` and an export
 * (PaymentIntentList, EXP-01).
 */
class ListPaymentIntentsRequest extends CompanyResourceRequest
{
    use SortsAndExports;

    public const FILTERS = ['all', ...PaymentIntent::STATUSES, 'unverified', 'mismatch'];

    protected string $resource = 'payment';

    protected array $readActions = ['view', 'match'];

    public function list(): ListDefinition
    {
        return new PaymentIntentList($this->route('company'));
    }

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in(self::FILTERS)],
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
