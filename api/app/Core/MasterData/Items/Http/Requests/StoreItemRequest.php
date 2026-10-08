<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Items\ItemPolicy;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * MD-02: a new item. Shared (no `company_id`): `core.item.create`
 * anywhere in the tenant; a company's: `core.item.create` at a scope
 * touching that company (TEN-08). A company the user does not reach is not
 * found; an unknown or other tenant's company fails validation (422).
 */
class StoreItemRequest extends FormRequest
{
    use GuardsFieldRules;

    /** RBAC-05: input refused when its field is hidden or read-only for the user. */
    protected string $fieldRulesResource = 'item';

    /** @var array<string, list<string>> input key => field rule names it writes */
    protected array $fieldRulesInputs = ['name_en' => ['name'], 'name_fr' => ['name']];

    public function authorize(): bool
    {
        $companyId = $this->input('company_id');
        $policy = app(ItemPolicy::class);

        // No create permission anywhere: forbidden, whatever the body names.
        if (! app(CompanyReach::class)->anywhere($this->user(), ['core.item.create'])) {
            return false;
        }

        if ($companyId === null) {
            return $policy->create($this->user());
        }

        $company = is_string($companyId) && Str::isUuid($companyId) ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return true;
        }

        abort_unless(app(CompanyReach::class)->reaches($this->user(), $company, [...ItemPolicy::PERMISSIONS, 'core.company.view']), 404);

        return $policy->create($this->user(), $company->id);
    }

    public function rules(): array
    {
        return ItemRules::rules(updating: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => ItemRules::validateItem(
            $validator, $validator->errors()->isEmpty() ? $validator->validated() : [], null, $this->user(),
        )];
    }

    public function attributes(): array
    {
        return ItemRules::attributeNames();
    }

    public function messages(): array
    {
        return ItemRules::messages();
    }
}
