<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Http\Lists\InvitationList;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;

/**
 * AUTH-05: GET invitations, for holders of `core.user.invite` (the list is
 * filtered to their scope by the controller, RBAC-04): `?search=` (name,
 * email or phone), `?per_page` (50, at most 200), `?sort` (newest first by
 * default) and an export (`?format`, `?columns[]`; InvitationList, EXP-01).
 */
class ListInvitationsRequest extends ListRequest
{
    use SortsAndExports;

    private ?InvitationList $definition = null;

    public function list(): ListDefinition
    {
        return $this->definition ??= new InvitationList($this->user());
    }

    public function authorize(): bool
    {
        return $this->user()->can(InvitationList::PERMISSION);
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
