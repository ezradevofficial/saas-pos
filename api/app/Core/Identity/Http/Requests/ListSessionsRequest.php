<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Http\Lists\SessionList;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-10: GET auth/sessions, the signed-in user's own sessions:
 * `?search=` (device name, IP address or browser), `?sort` (most recently
 * active first by default) and an export (`?format`, `?columns[]`;
 * SessionList, EXP-01).
 *
 * Paging: every session when neither `?page` nor `?per_page` is sent (as
 * before paging existed; the list is short), else pages of `?per_page`
 * (50, at most 200) with the usual meta.
 */
class ListSessionsRequest extends FormRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new SessionList;
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
