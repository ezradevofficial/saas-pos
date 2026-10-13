<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Validation\Rule;
use Modules\Accounting\Http\Lists\JournalList;
use Modules\Accounting\Models\Journal;

/** GET companies/{company}/accounting/journals (ACC-03): `?status=`, `?kind=`, dates, search by number, reference or memo, export. */
class ListJournalsRequest extends ListRequest
{
    public function list(): ListDefinition
    {
        return new JournalList;
    }

    protected function statuses(): array
    {
        return Journal::STATUSES;
    }

    protected function permission(): string
    {
        return 'accounting.journal.view';
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'kind' => ['sometimes', 'string', Rule::in(Journal::KINDS)],
        ];
    }
}
