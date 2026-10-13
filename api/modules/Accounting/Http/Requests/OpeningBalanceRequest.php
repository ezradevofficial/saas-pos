<?php

namespace Modules\Accounting\Http\Requests;

/** IMP-01, ACC-20: one opening balance row (the import framework's per-row check), as a journal line. */
class OpeningBalanceRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.create';
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts')],
            'debit' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'credit' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'currency' => ['sometimes', 'string', 'size:3', $this->activeCurrency()],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?\z/'],
            'reference' => ['nullable', 'string', 'max:80'],
        ];
    }
}
