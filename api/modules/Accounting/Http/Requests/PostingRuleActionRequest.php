<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/posting-rules/{acc_posting_rule}/archive. */
class PostingRuleActionRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.posting_rule.manage';
    }
}
