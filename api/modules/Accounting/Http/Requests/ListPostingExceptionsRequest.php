<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** GET companies/{company}/accounting/posting-exceptions (ACC-07), `?status=open|resolved|superseded|all`. */
class ListPostingExceptionsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.posting_rule.view';
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['open', 'resolved', 'superseded', 'all'])],
        ];
    }
}
