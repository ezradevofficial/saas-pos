<?php

namespace App\Core\MasterData\Parties\Http\Requests;

use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Parties\Http\Lists\PartyList;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartyRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MD-01: list parties the user can view (`core.party.view` anywhere):
 * `?role=customer|supplier|contact|employee_link`, `?tag=`, `?search=`
 * (name, legal name, tax ID or phone digits), `?status`, `?per_page`,
 * `?sort` and an export (`?format`, `?columns[]`; PartyList, EXP-01).
 */
class ListPartiesRequest extends FormRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new PartyList;
    }

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
            // CF-03: `?custom[key]=value` or `?custom[key][min|max]=` (CustomFieldLists).
            'custom' => ['sometimes', 'array', app(CustomFieldLists::class)->filterRule(PartyEntity::KEY, $this->list(), $this)],
        ];
    }
}
