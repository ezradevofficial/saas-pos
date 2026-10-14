<?php

namespace Modules\Accounting\Http\Requests;

/** GET accounting/journals/{acc_journal}. */
class ShowJournalRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.view';
    }
}
