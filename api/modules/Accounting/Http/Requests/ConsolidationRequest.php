<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * GET accounting/consolidation (ACC-18, TEN-07): the group's statements
 * over every active company of the tenant; the user holds
 * `accounting.report.consolidate` at each of them (spec: Permissions),
 * `?from=`, `?to=`, `?currency=` (an active tenant currency; the first
 * company's base by default).
 */
class ConsolidationRequest extends FormRequest
{
    /** The group is every active company of the tenant: the user needs the permission at each one (403 otherwise). */
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);
        $companies = $this->companies();

        return $companies->isNotEmpty()
            && $companies->every(fn (Company $company) => $resolver->can($this->user(), 'accounting.report.consolidate', Scope::company($company->id)));
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
        ];
    }

    /** @return Collection<int, Company> the companies of the group (every active company), by name */
    public function companies(): Collection
    {
        return Company::query()->whereNull('archived_at')->orderBy('name')->orderBy('id')->get();
    }
}
