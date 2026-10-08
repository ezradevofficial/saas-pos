<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared tax category validation (MD-03). `codes` sets the default tax
 * code per company: [{company_id, tax_code_id}], tax_code_id null removes
 * that company's default; companies not listed keep theirs. Each code is an
 * active code of that company, the user edits taxes there, and a
 * company's own category only maps its own company.
 */
final class TaxCategoryRules
{
    /** @return array<string, list<mixed>> */
    public static function codeRules(): array
    {
        $company = Rule::exists('companies', 'id')->whereNull('archived_at');

        return [
            'codes' => ['sometimes', 'array', 'max:100'],
            'codes.*' => ['array:company_id,tax_code_id'],
            'codes.*.company_id' => ['required', 'uuid', 'distinct', $company],
            'codes.*.tax_code_id' => ['present', 'nullable', 'uuid'],
        ];
    }

    public static function validateCodes(Validator $validator, array $codes, ?string $categoryCompanyId, User $user): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        foreach (array_values($codes) as $index => $entry) {
            $companyId = $entry['company_id'];

            if ($categoryCompanyId !== null && $companyId !== $categoryCompanyId) {
                $validator->errors()->add("codes.{$index}.company_id", __('core.tax.category_other_company'));

                continue;
            }

            if (! $user->can('core.tax.edit', Company::query()->findOrFail($companyId))) {
                $validator->errors()->add("codes.{$index}.company_id", __('core.tax.category_company_not_allowed'));

                continue;
            }

            if ($entry['tax_code_id'] !== null && ! TaxCode::query()->active()
                ->whereKey($entry['tax_code_id'])->where('company_id', $companyId)->exists()) {
                $validator->errors()->add("codes.{$index}.tax_code_id", __('core.tax.category_code_invalid'));
            }
        }
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'name' => __('core.tax.attributes.category_name'),
            'company_id' => __('core.tax.attributes.company'),
            'codes' => __('core.tax.attributes.codes'),
            'codes.*.company_id' => __('core.tax.attributes.company'),
            'codes.*.tax_code_id' => __('core.tax.attributes.tax_code'),
        ];
    }
}
