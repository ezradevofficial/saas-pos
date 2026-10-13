<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** GET companies/{company}/accounting/posting-rules (ACC-07), `?status=active|archived|all`. */
class ListPostingRulesRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.posting_rule.view';
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
        ];
    }
}
