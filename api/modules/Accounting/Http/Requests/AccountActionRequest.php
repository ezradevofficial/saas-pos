<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/accounts/{acc_account}/archive|restore (TEN-06). */
class AccountActionRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.account.manage';
    }
}
