<?php

namespace Modules\Accounting\Http\Requests;

/** GET accounting/budgets/{acc_budget}, and budget against actual (ACC-16). */
class ShowBudgetRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.budget.view';
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
