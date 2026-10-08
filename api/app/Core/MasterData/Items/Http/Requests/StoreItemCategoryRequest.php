<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Items\ItemCategoryPolicy;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * MD-02: a new item category, shared (`core.item_category.create`
 * anywhere) or one company's (`core.item_category.create` at a scope
 * touching it), following the items sharing mode (TEN-08).
 */
class StoreItemCategoryRequest extends FormRequest
{
    use GuardsFieldRules;

    /** RBAC-05: input refused when its field is hidden or read-only for the user. */
    protected string $fieldRulesResource = 'item_category';

    public function authorize(): bool
    {
        $companyId = $this->input('company_id');
        $policy = app(ItemCategoryPolicy::class);

        if (! app(CompanyReach::class)->anywhere($this->user(), ['core.item_category.create'])) {
            return false;
        }

        if ($companyId === null) {
            return $policy->create($this->user());
        }

        $company = is_string($companyId) && Str::isUuid($companyId) ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return true;
        }

        abort_unless(app(CompanyReach::class)->reaches($this->user(), $company, [...ItemCategoryPolicy::PERMISSIONS, 'core.company.view']), 404);

        return $policy->create($this->user(), $company->id);
    }

    public function rules(): array
    {
        return ItemCategoryRules::rules(updating: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => ItemCategoryRules::validateCategory(
            $validator, $validator->errors()->isEmpty() ? $validator->validated() : [], null,
        )];
    }

    public function attributes(): array
    {
        return ItemCategoryRules::attributeNames();
    }

    public function messages(): array
    {
        return ItemCategoryRules::messages();
    }
}
