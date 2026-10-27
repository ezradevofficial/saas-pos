<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;
use Modules\Hotel\Http\Lists\ChargeExceptionList;

/** GET hotel/charge-exceptions (HOT-22, HOT-31): `hotel.folio.view` at the reader's branches. `?branch=`, `?status=open|resolved|all`, `?sort=`, pages and an export. */
class ListChargeExceptionsRequest extends HotelRequest
{
    use SortsAndExports;

    protected function permission(): string
    {
        return 'hotel.folio.view';
    }

    public function list(): ChargeExceptionList
    {
        return new ChargeExceptionList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'status' => ['sometimes', 'string', Rule::in(['open', 'resolved', 'all'])],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
