<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/bank-statement-lines/{acc_bank_statement_line}/post: a journal for the line, matched (ACC-14). */
class PostLineRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.reconcile';
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts')],
            'cost_centre_id' => ['nullable', 'uuid', $this->ofCompany('cost_centres')],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
