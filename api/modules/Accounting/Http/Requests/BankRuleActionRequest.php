<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/bank-rules/{acc_bank_rule}/archive. */
class BankRuleActionRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.manage';
    }
}
