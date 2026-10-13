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
 * over every company where the user holds `accounting.report.consolidate`,
 * `?from=`, `?to=`, `?currency=` (an active tenant currency; the first
 * company's base by default).
 */
class ConsolidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'accounting.report.consolidate');
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
        ];
    }

    /** @return Collection<int, Company> the companies of the group, by name */
    public function companies(): Collection
    {
        $resolver = app(ScopeResolver::class);

        return Company::query()->orderBy('name')->orderBy('id')->get()
            ->filter(fn (Company $company) => $resolver->can($this->user(), 'accounting.report.consolidate', Scope::company($company->id)))
            ->values();
    }
}
