<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Accounting\AccountingServiceProvider;

/**
 * ACC-22, RBAC-04: every Accounting request acts at one company: the
 * route's `{company}` or the company of the record it names. The
 * permission is checked there. A user holding no Accounting permission
 * at that company gets 404 (ids out of scope are never confirmed); one
 * who sees the company's ledger but lacks this permission gets 403.
 */
abstract class AccountingRequest extends FormRequest
{
    private ?Company $resolvedCompany = null;

    abstract protected function permission(): string;

    public function authorize(): bool
    {
        $company = $this->company();

        if ($this->user()->can($this->permission(), Scope::company($company->id))) {
            return true;
        }

        abort_unless(self::inSight($this->user(), $company->id), 404);

        return false;
    }

    public function rules(): array
    {
        return [];
    }

    /** The company the request acts at. */
    public function company(): Company
    {
        if ($this->resolvedCompany !== null) {
            return $this->resolvedCompany;
        }

        $company = $this->route('company');

        if ($company instanceof Company) {
            return $this->resolvedCompany = $company;
        }

        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model && $parameter->getAttribute('company_id') !== null) {
                return $this->resolvedCompany = Company::query()->findOrFail($parameter->getAttribute('company_id'));
            }
        }

        abort(404);
    }

    /** Whether $user holds any Accounting permission at the company (or above it). */
    public static function inSight($user, string $companyId): bool
    {
        $resolver = app(ScopeResolver::class);
        $scope = Scope::company($companyId);

        foreach (AccountingServiceProvider::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                if ($resolver->can($user, "accounting.{$resource}.{$action}", $scope)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** `exists` under row-level security, limited to the request's company. */
    protected function ofCompany(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('company_id', $this->company()->id);
    }

    /** CUR-01: one of the tenant's active currencies. */
    protected function activeCurrency(): Exists
    {
        return Rule::exists('tenant_currencies', 'code')->where('active', true);
    }
}
