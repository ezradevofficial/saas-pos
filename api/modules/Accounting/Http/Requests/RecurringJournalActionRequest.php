<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/recurring-journals/{acc_recurring_journal}/archive. */
class RecurringJournalActionRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.create';
    }
}
