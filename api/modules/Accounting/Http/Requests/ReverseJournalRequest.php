<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/journals/{acc_journal}/reverse (ACC-05). */
class ReverseJournalRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.reverse';
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
