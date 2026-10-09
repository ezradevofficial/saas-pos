<?php

namespace App\Core\MasterData\Parties\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use Illuminate\Validation\Validator;

/**
 * MD-01: change a party (`core.party.edit`). Lists given (phones, emails,
 * addresses, tags, roles) replace the stored ones. Moving it to another
 * company needs `core.party.edit` there too; roles that become all shared
 * make it shared (TEN-08).
 */
class UpdatePartyRequest extends PartyRequest
{
    use GuardsFieldRules;

    /** RBAC-05: input refused when its field is hidden or read-only for the user. */
    protected string $fieldRulesResource = 'party';

    /** @var array<string, list<string>> input key => field rule names it writes */
    protected array $fieldRulesInputs = ['credit_limit' => ['credit_limit_minor', 'credit_limit_currency'], 'credit_limit_currency' => ['credit_limit_minor', 'credit_limit']];

    protected string $ability = 'update';

    public function rules(): array
    {
        return PartyRules::rules(updating: true);
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => PartyRules::validateParty(
                $validator, $validator->errors()->isEmpty() ? $validator->validated() : [], $this->party(), $this->user(), 'core.party.edit',
            ),
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, PartyEntity::KEY, $this->input('custom'), $this->party(), $this->user()),
        ];
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
