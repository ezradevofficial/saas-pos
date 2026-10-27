<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pharmacy\Models\ItemProfile;

/**
 * GET and PUT companies/{company}/pharmacy-settings (PHA-02):
 * `pharmacy.settings.view` to read, `pharmacy.settings.edit` to change, at
 * the company; a company the user holds nothing at is not found. PUT body:
 * `{near_expiry_days?, min_sale_days?, prescription_classes?, pharmacist_classes?,
 * prescription_valid_days? (null: not checked), quarantine_expired?}`.
 */
class CompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        $permission = $this->isMethod('GET') ? 'pharmacy.settings.view' : 'pharmacy.settings.edit';

        $resolver = app(ScopeResolver::class);
        $scope = Scope::company($company->id);

        // A company the user holds nothing at is not found; one they only read cannot be changed (403).
        abort_unless($resolver->can($this->user(), 'pharmacy.settings.view', $scope) || $resolver->can($this->user(), 'pharmacy.settings.edit', $scope), 404);

        return $resolver->can($this->user(), $permission, $scope);
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return [
            'near_expiry_days' => ['sometimes', 'integer', 'between:0,730'],
            'min_sale_days' => ['sometimes', 'integer', 'between:0,365'],
            'prescription_classes' => ['sometimes', 'array'],
            'prescription_classes.*' => ['required', 'string', Rule::in(ItemProfile::CLASSES), 'distinct'],
            'pharmacist_classes' => ['sometimes', 'array'],
            'pharmacist_classes.*' => ['required', 'string', Rule::in(ItemProfile::CLASSES), 'distinct'],
            'prescription_valid_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'quarantine_expired' => ['sometimes', 'boolean'],
        ];
    }
}
