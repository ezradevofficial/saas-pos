<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/journals/{acc_journal}/submit: to its flow (ACC-04). */
class SubmitJournalRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.create';
    }
}
