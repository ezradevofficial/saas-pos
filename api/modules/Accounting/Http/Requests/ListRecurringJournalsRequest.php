<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\RecurringJournal;

/** GET companies/{company}/accounting/recurring-journals (ACC-06), `?status=`. */
class ListRecurringJournalsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.view';
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([...RecurringJournal::STATUSES, 'all'])],
        ];
    }
}
