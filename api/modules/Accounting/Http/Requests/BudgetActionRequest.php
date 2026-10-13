<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/budgets/{acc_budget}/archive. */
class BudgetActionRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.budget.manage';
    }
}
