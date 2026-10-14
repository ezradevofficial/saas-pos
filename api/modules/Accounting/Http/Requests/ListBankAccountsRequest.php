<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** GET companies/{company}/accounting/bank-accounts (ACC-12), `?status=`. */
class ListBankAccountsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.view';
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
        ];
    }
}
