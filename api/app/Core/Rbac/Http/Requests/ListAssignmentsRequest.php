<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Rbac\Http\Lists\AssignmentList;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RBAC-04: GET users/{user}/assignments, the roles a user holds where the
 * reader holds `core.user.view` (a user out of the reader's scope is not
 * found, 404): `?search=` (role name), `?sort` (oldest grant first by
 * default) and an export (`?format`, `?columns[]`; AssignmentList,
 * EXP-01).
 *
 * Paging: every assignment when neither `?page` nor `?per_page` is sent
 * (as before paging existed), else pages of `?per_page` (50, at most 200)
 * with the usual meta.
 */
class ListAssignmentsRequest extends FormRequest
{
    use SortsAndExports;

    private ?AssignmentList $definition = null;

    public function list(): ListDefinition
    {
        return $this->definition ??= new AssignmentList($this->holder());
    }

    public function holder(): User
    {
        return $this->route('user');
    }

    public function authorize(): bool
    {
        // RBAC-04: a user out of scope is not found, as on GET users/{user}.
        abort_unless($this->user()->can('view', $this->holder()), 404);

        return true;
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
