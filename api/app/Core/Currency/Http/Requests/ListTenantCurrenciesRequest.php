<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Currency\Http\Lists\TenantCurrencyList;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;

/**
 * CUR-01: GET tenant/currencies (`core.currency.view` anywhere):
 * `?search=` (code, or the name in the reader's language), `?sort` (by
 * code by default) and an export (`?format`, `?columns[]`;
 * TenantCurrencyList, EXP-01).
 *
 * Paging: every currency when neither `?page` nor `?per_page` is sent (as
 * before paging existed: pickers fetch the whole short list), else pages
 * of `?per_page` (50, at most 200) with the usual meta.
 */
class ListTenantCurrenciesRequest extends CurrencyViewRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new TenantCurrencyList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
