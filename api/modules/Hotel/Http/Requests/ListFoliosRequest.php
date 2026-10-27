<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;
use Modules\Hotel\Http\Lists\FolioList;

/**
 * GET hotel/folios (HOT-20, HOT-31): `hotel.folio.view` at the reader's branches. `?branch=`, `?status=open|settled|all`, `?kind=guest|master`,
 * `?with_balance=1` (only folios with a balance), `?search=` (folio number, booking number, guest name), `?sort=`, pages and an export.
 */
class ListFoliosRequest extends HotelRequest
{
    use SortsAndExports;

    protected function permission(): string
    {
        return 'hotel.folio.view';
    }

    public function list(): FolioList
    {
        return new FolioList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'status' => ['sometimes', 'string', Rule::in(['open', 'settled', 'all'])],
            'kind' => ['sometimes', 'string', Rule::in(['guest', 'master'])],
            'with_balance' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
