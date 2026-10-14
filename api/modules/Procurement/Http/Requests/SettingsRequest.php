<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Visibility;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET and PUT procurement/companies/{company}/settings (PRC-01): reading
 * needs `procurement.po.view` or `procurement.invoice.view` at, above or
 * beneath the company; changing needs `procurement.settings.edit` at the
 * company. Body `{price_tolerance_percent?, qty_tolerance_percent?,
 * over_receipt_percent?}` (0 to 100, up to 4 decimals).
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        if ($this->isMethod('GET')) {
            $visibility = app(Visibility::class);

            return $visibility->reaches($this->user(), 'procurement.po.view', $company) || $visibility->reaches($this->user(), 'procurement.invoice.view', $company);
        }

        return app(ScopeResolver::class)->can($this->user(), 'procurement.settings.edit', Scope::company($company->id));
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return [
            'price_tolerance_percent' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,4'],
            'qty_tolerance_percent' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,4'],
            'over_receipt_percent' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,4'],
        ];
    }
}
