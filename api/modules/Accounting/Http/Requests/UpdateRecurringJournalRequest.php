<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\RecurringJournal;

/** PATCH accounting/recurring-journals/{acc_recurring_journal} (ACC-06): fields, or `status` active|paused. */
class UpdateRecurringJournalRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.create';
    }

    public function rules(): array
    {
        $req = 'sometimes';

        return [
            'status' => ['sometimes', 'string', Rule::in(['active', 'paused'])],
            'name' => [$req, 'string', 'max:150'],
            'frequency' => [$req, 'string', Rule::in(RecurringJournal::FREQUENCIES)],
            'next_run_on' => [$req, 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'lines' => [$req, 'array', 'min:2', 'max:100'],
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
        ];
    }
}
