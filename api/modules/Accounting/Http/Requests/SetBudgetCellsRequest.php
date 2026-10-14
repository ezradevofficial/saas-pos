<?php

namespace Modules\Accounting\Http\Requests;

/** PUT accounting/budgets/{acc_budget}/cells (ACC-16). */
class SetBudgetCellsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.budget.manage';
    }

    public function rules(): array
    {
        return [
            'cells' => ['required', 'array', 'min:1', 'max:2000'],
            'cells.*.account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts')],
            'cells.*.cost_centre_id' => ['nullable', 'uuid', $this->ofCompany('cost_centres')],
            'cells.*.month' => ['required', 'integer', 'between:1,12'],
            'cells.*.amount' => ['nullable', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?\z/'],
        ];
    }
}
