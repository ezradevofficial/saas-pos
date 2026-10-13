<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** POST companies/{company}/accounting/journals: a manual journal draft (ACC-04). */
class StoreJournalRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.create';
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts')],
            'lines.*.debit' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'lines.*.credit' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'lines.*.rate' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?\z/'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.party_id' => ['nullable', 'uuid', Rule::exists('parties', 'id')],
            'lines.*.branch_id' => ['nullable', 'uuid', $this->ofCompany('branches')],
            'lines.*.cost_centre_id' => ['nullable', 'uuid', $this->ofCompany('cost_centres')],
            'lines.*.department_id' => ['nullable', 'uuid', $this->ofCompany('departments')],
            'lines.*.project_id' => ['nullable', 'uuid', $this->ofCompany('projects')],
            'lines.*.currency' => ['sometimes', 'string', 'size:3', $this->activeCurrency()],
        ];
    }
}
