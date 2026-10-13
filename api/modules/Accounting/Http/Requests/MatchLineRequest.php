<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/bank-statement-lines/{acc_bank_statement_line}/match (ACC-14). */
class MatchLineRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.reconcile';
    }

    public function rules(): array
    {
        return [
            'journal_line_ids' => ['required', 'array', 'min:1', 'max:50'],
            'journal_line_ids.*' => ['required', 'uuid', 'distinct', $this->ofCompany('acc_journal_lines')],
        ];
    }
}
