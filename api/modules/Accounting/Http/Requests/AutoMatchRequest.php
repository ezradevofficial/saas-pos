<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/bank-accounts/{acc_bank_account}/auto-match (ACC-14). */
class AutoMatchRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.reconcile';
    }
}
