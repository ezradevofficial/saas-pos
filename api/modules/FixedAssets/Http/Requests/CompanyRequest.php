<?php

namespace Modules\FixedAssets\Http\Requests;

use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Modules\FixedAssets\Support\Access;

/**
 * FA-29: company-wide requests (settings, categories, runs, reports, the
 * tax book): the company comes from the route (`{company}`, found under
 * RLS) or the body or query (`company_id`, `company`); the permission is
 * checked at that company (RBAC-04, 403), so a branch role does not change
 * a company's settings. A company of another tenant is not found, so the
 * validation (`exists` under RLS) refuses it. Subclasses name the
 * permission and their rules.
 */
abstract class CompanyRequest extends FormRequest
{
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        $id = $this->companyId();
        $access = app(Access::class);

        return $id !== null && Company::query()->whereKey($id)->exists()
            ? $access->atCompany($this->user(), $this->permission(), $id)
            : $access->anywhere($this->user(), $this->permission());
    }

    public function company(): Company
    {
        return Company::query()->findOrFail($this->companyId());
    }

    protected function companyId(): ?string
    {
        $route = $this->route('company');

        if ($route instanceof Company) {
            return $route->id;
        }

        // A body names it `company_id`, a query `company`.
        $id = $this->all()['company_id'] ?? $this->all()['company'] ?? null;

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }
}
