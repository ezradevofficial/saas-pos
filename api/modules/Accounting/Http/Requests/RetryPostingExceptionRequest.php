<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/posting-exceptions/{acc_posting_exception}/retry (ACC-07). */
class RetryPostingExceptionRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.posting_rule.manage';
    }
}
