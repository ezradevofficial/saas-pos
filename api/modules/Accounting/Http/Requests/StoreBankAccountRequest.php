<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\BankAccount;

/** POST companies/{company}/accounting/bank-accounts (ACC-12). */
class StoreBankAccountRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.manage';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', Rule::in(BankAccount::KINDS)],
            'account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts'), Rule::unique('acc_bank_accounts', 'account_id')],
            'currency' => ['required', 'string', 'size:3', $this->activeCurrency()],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'payment_method_id' => ['nullable', 'uuid', $this->ofCompany('payment_methods')],
        ];
    }
}
