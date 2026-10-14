<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/bank-statement-lines/{acc_bank_statement_line}/unmatch (ACC-14). */
class UnmatchLineRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.reconcile';
    }
}
