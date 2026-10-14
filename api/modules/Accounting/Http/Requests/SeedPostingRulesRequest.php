<?php

namespace Modules\Accounting\Http\Requests;

/** POST companies/{company}/accounting/posting-rules/defaults: the template's default rules and accounts the company is missing (ACC-24). */
class SeedPostingRulesRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.posting_rule.manage';
    }

    public function rules(): array
    {
        return [];
    }
}
