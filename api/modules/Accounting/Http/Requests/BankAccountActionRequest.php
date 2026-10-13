<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/bank-accounts/{acc_bank_account}/archive. */
class BankAccountActionRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.manage';
    }
}
