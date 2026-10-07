<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Identity\Services\SignUp;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/** TEN-03: a company is created at tenant scope; currency and time zone default from the country. */
class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Company::class);
    }

    public function rules(): array
    {
        return CompanyRules::rules(updating: false);
    }

    /** Validated attributes with the country's defaults filled in. */
    public function companyAttributes(): array
    {
        $data = $this->validated();
        $country = SignUp::COUNTRIES[$data['country']];

        return array_merge($data, [
            'legal_name' => $data['legal_name'] ?? $data['name'],
            'base_currency' => $data['base_currency'] ?? $country['currency'],
            'timezone' => $data['timezone'] ?? $country['timezone'],
            'fiscal_year_start_month' => $data['fiscal_year_start_month'] ?? 1,
            'address' => $data['address'] ?? [],
        ]);
    }
}
