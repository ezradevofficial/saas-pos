<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Validation\Rule;
use Modules\Accounting\Http\Lists\DocumentList;
use Modules\Accounting\Models\Document;

/** GET companies/{company}/accounting/documents (ACC-09): `?kind=`, `?status=` (open: posted with something left to settle), `?party=`, dates, search, export. */
class ListDocumentsRequest extends ListRequest
{
    public function list(): ListDefinition
    {
        return new DocumentList;
    }

    protected function statuses(): array
    {
        return [...Document::STATUSES, 'open'];
    }

    protected function permission(): string
    {
        return 'accounting.invoice.view';
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'kind' => ['sometimes', 'string', Rule::in([...Document::KINDS, 'customer', 'supplier'])],
            'party' => ['sometimes', 'uuid', Rule::exists('parties', 'id')],
        ];
    }
}
