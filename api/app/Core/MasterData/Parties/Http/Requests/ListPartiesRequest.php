<?php

namespace App\Core\MasterData\Parties\Http\Requests;

use App\Core\MasterData\Http\Requests\ListsArchivable;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartyRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MD-01: list parties the user can view (`core.party.view` anywhere):
 * `?role=customer|supplier|contact|employee_link`, `?tag=`, `?search=`
 * (name, legal name, tax ID or phone digits), `?status`, `?per_page`.
 */
class ListPartiesRequest extends FormRequest
{
    use ListsArchivable;

    public function authorize(): bool
    {
        return app(PartyPolicy::class)->viewAny($this->user());
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'role' => ['sometimes', 'string', Rule::in(PartyRoles::ALL)],
            'tag' => ['sometimes', 'string', 'max:40'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
