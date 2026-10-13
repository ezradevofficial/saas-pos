<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventorySetting;

/**
 * GET inventory/companies/{company}/settings (`inventory.stock.view`
 * anywhere) and PUT (`inventory.settings.edit` at the company;
 * `{costing_method, allow_negative}`; INV-12, INV-13). Another tenant's
 * company is not found (row-level security).
 */
class CompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        if ($this->isMethod('GET')) {
            return $resolver->can($this->user(), 'inventory.stock.view') || $resolver->can($this->user(), 'inventory.settings.edit');
        }

        return $resolver->can($this->user(), 'inventory.settings.edit', Scope::company($this->company()->id));
    }

    public function company(): Company
    {
        return $this->route('company');
    }

    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : [
            'costing_method' => ['required', 'string', Rule::in(InventorySetting::METHODS)],
            'allow_negative' => ['required', 'boolean'],
        ];
    }
}
