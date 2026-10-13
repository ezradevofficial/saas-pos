<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** GET companies/{company}/accounting/budgets (ACC-16), `?status=`. */
class ListBudgetsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.budget.view';
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
        ];
    }
}
