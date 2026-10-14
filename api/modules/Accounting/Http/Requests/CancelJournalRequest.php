<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/journals/{acc_journal}/cancel, with a reason (ACC-04). */
class CancelJournalRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.create';
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
