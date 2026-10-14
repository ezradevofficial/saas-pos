<?php

namespace Modules\Accounting\Http\Requests;

/** GET accounting/bank-accounts/{acc_bank_account}/reconciliation (ACC-14). */
class ShowReconciliationRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.view';
    }
}
