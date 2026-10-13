<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** GET companies/{company}/accounting/bank-rules (ACC-14), `?status=`. */
class ListBankRulesRequest extends AccountingRequest
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
