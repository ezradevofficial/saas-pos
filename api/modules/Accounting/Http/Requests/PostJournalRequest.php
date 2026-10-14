<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/journals/{acc_journal}/post: an approved journal whose posting was refused, again (ACC-04). */
class PostJournalRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.approve';
    }
}
