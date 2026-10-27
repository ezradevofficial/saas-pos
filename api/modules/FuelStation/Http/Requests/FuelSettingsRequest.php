<?php

namespace Modules\FuelStation\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Modules\FuelStation\Support\Access;

/**
 * GET and PUT companies/{company}/fuel-settings (FUEL-03): `fuel.settings.view`
 * to read, `fuel.settings.edit` to change, for the company; a company the user
 * holds nothing at is not found. PUT body: any of the tolerances (see
 * Present::settings): minor units as integers, litres with up to 3 decimals,
 * percents between 0 and 100.
 */
class FuelSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        $permission = $this->isMethod('GET') ? 'fuel.settings.view' : 'fuel.settings.edit';
        $resolver = app(ScopeResolver::class);
        $access = app(Access::class);
        $scope = Scope::company($company->id);

        // A company the user holds nothing at is not found; one they only read cannot be changed (403).
        abort_unless($resolver->can($this->user(), 'fuel.settings.view', $scope) || $resolver->can($this->user(), 'fuel.settings.edit', $scope)
            || $access->canCompany($this->user(), 'fuel.settings.view', $company->id) || $access->canCompany($this->user(), 'fuel.settings.edit', $company->id), 404);

        // Settings are the whole company's: changing them needs the permission for the company, not one branch.
        return $this->isMethod('GET') ? $access->canCompany($this->user(), $permission, $company->id) : $access->canCompanyWide($this->user(), $permission, $company->id);
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return [
            'cash_variance_tolerance_minor' => FuelRules::minor(false),
            'litre_tolerance' => FuelRules::litres(false),
            'loss_tolerance_percent' => ['sometimes', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'loss_tolerance_litres' => FuelRules::litres(false),
            'test_tolerance_litres' => FuelRules::litres(false),
            'delivery_tolerance_percent' => ['sometimes', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
        ];
    }
}
