<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\BankRule;

/** POST companies/{company}/accounting/bank-rules (ACC-14). */
class StoreBankRuleRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.manage';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contains' => ['required', 'string', 'max:100'],
            'direction' => ['sometimes', 'string', Rule::in(BankRule::DIRECTIONS)],
            'account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts')],
            'cost_centre_id' => ['nullable', 'uuid', $this->ofCompany('cost_centres')],
            'bank_account_id' => ['nullable', 'uuid', $this->ofCompany('acc_bank_accounts')],
        ];
    }
}
