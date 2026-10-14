<?php

namespace Modules\Accounting\Http\Requests;

/** GET accounting/accounts/{acc_account}. */
class ShowAccountRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.account.view';
    }
}
