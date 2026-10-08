<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Identity\Services\SignUp;
use Illuminate\Validation\Rule;

/** Shared company validation (TEN-03). */
final class CompanyRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $optional = $updating ? ['sometimes', 'required'] : ['nullable'];

        return [
            'name' => [...$required, 'string', 'max:255'],
            'legal_name' => [...$optional, 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country' => [...$required, 'string', 'in:'.implode(',', array_keys(SignUp::COUNTRIES))],
            // CUR-01: a current ISO 4217 currency of the catalogue.
            'base_currency' => [...$optional, 'string', 'regex:/^[A-Z]{3}$/', Rule::exists('currencies', 'code')->where('active_in_iso', true)],
            'fiscal_year_start_month' => [...$optional, 'integer', 'between:1,12'],
            'timezone' => [...$optional, 'string', 'timezone:all'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
