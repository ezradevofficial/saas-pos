<?php

namespace App\Core\MasterData\Parties\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * MD-01: change a party (`core.party.edit`). Lists given (phones, emails,
 * addresses, tags, roles) replace the stored ones. Moving it to another
 * company needs `core.party.edit` there too; roles that become all shared
 * make it shared (TEN-08).
 */
class UpdatePartyRequest extends PartyRequest
{
    protected string $ability = 'update';

    public function rules(): array
    {
        return PartyRules::rules(updating: true);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => PartyRules::validateParty(
            $validator, $validator->errors()->isEmpty() ? $validator->validated() : [], $this->party(), $this->user(), 'core.party.edit',
        )];
    }

    public function attributes(): array
    {
        return PartyRules::attributeNames();
    }

    public function messages(): array
    {
        return PartyRules::messages();
    }
}
