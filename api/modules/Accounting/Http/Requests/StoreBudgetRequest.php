<?php

namespace Modules\Accounting\Http\Requests;

/** POST companies/{company}/accounting/budgets (ACC-16). */
class StoreBudgetRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.budget.manage';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
        ];
    }
}
