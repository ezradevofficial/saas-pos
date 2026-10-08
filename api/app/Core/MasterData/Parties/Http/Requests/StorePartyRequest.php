<?php

namespace App\Core\MasterData\Parties\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * MD-01: a new party. Shared (no `company_id`): `core.party.create`
 * anywhere in the tenant; a company's: `core.party.create` at a scope
 * touching that company (TEN-08). A company the user does not reach is not
 * found; an unknown or other tenant's company fails validation (422).
 */
class StorePartyRequest extends FormRequest
{
    use GuardsFieldRules;

    /** RBAC-05: input refused when its field is hidden or read-only for the user. */
    protected string $fieldRulesResource = 'party';

    /** @var array<string, list<string>> input key => field rule names it writes */
    protected array $fieldRulesInputs = ['credit_limit' => ['credit_limit_minor', 'credit_limit_currency'], 'credit_limit_currency' => ['credit_limit_minor', 'credit_limit']];

    public function authorize(): bool
    {
        $companyId = $this->input('company_id');
        $policy = app(PartyPolicy::class);

        // No create permission anywhere: forbidden, whatever the body names.
        if (! app(CompanyReach::class)->anywhere($this->user(), ['core.party.create'])) {
            return false;
        }

        if ($companyId === null) {
            return $policy->create($this->user());
        }

        $company = is_string($companyId) && Str::isUuid($companyId) ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return true;
        }

        abort_unless(app(CompanyReach::class)->reaches($this->user(), $company, [...PartyPolicy::PERMISSIONS, 'core.company.view']), 404);

        return $policy->create($this->user(), $company->id);
    }

    public function rules(): array
    {
        return PartyRules::rules(updating: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => PartyRules::validateParty(
            $validator, $validator->errors()->isEmpty() ? $validator->validated() : [], null, $this->user(), 'core.party.create',
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
