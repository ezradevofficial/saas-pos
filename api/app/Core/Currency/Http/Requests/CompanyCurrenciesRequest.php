<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CUR-02: a company's base and reporting currencies, at the company's
 * scope. Out of scope is not found (RBAC-04); in scope without the
 * permission is forbidden.
 */
class CompanyCurrenciesRequest extends FormRequest
{
    protected string $permission = 'core.currency.view';

    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        abort_unless($this->user()->can('view', $company), 404);

        return $this->user()->can($this->permission, $company);
    }

    public function rules(): array
    {
        return [];
    }
}
