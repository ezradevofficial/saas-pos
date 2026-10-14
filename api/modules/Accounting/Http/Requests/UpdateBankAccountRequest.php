<?php

namespace Modules\Accounting\Http\Requests;

/** PATCH accounting/bank-accounts/{acc_bank_account} (ACC-12). */
class UpdateBankAccountRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.manage';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'payment_method_id' => ['nullable', 'uuid', $this->ofCompany('payment_methods')],
        ];
    }
}
