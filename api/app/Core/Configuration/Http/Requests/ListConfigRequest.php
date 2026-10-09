<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * LAY-06: GET config/{kind}: the kind's documents the user may see
 * (RBAC-04), `?key=` for one key, `?scope_type=` (and `?scope_id=`, not
 * for the tenant) for one scope, in pages of `?per_page`. Payloads are
 * never listed. Needs one of the kind's permissions somewhere.
 */
class ListConfigRequest extends ConfigKindRequest
{
    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:100'],
            'scope_type' => ['required_with:scope_id', 'string', Rule::in(ConfigDocument::SCOPES)],
            // The tenant scope has no id; every other scope names one.
            'scope_id' => ['nullable', 'uuid', 'prohibited_if:scope_type,'.ConfigDocument::TENANT, 'required_unless:scope_type,'.ConfigDocument::TENANT.',null'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
