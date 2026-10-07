<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        // RBAC-04: out of scope is not found, never forbidden.
        abort_unless($this->user()->can('view', $company), 404);

        return $this->user()->can('update', $company);
    }

    public function rules(): array
    {
        return CompanyRules::rules(updating: true);
    }
}
