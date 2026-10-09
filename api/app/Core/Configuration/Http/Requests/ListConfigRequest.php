<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Tenancy\Http\Requests\ListRequest;

/**
 * LAY-06: GET config/{kind}: the kind's documents the user may see
 * (RBAC-04), `?key=` for one key, in pages of `?per_page`. Needs one of
 * the kind's permissions somewhere.
 */
class ListConfigRequest extends ConfigKindRequest
{
    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
