<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\Taxes\TaxCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared tax code validation (MD-03): codes are upper case and unique among the company's active codes. */
final class TaxCodeRules
{
    /** A percentage between 0 and 100 with at most 4 decimals. */
    public const RATE = ['regex:/^\d{1,3}(\.\d{1,4})?\z/', 'numeric', 'between:0,100'];

    /** @return array<string, list<mixed>> */
    public static function rules(string $companyId, ?TaxCode $code, bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $unique = Rule::unique('tax_codes', 'code')->where('company_id', $companyId)->whereNull('archived_at');

        if ($code !== null) {
            $unique->ignore($code->id);
        }

        return [
            'code' => [...$required, 'string', 'max:30', 'regex:/^[A-Z0-9_\-]+\z/', $unique],
            'name_en' => [...$required, 'string', 'max:255'],
            'name_fr' => [...$required, 'string', 'max:255'],
            'fiscal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    public static function normaliseCode(FormRequest $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'code' => __('core.tax.attributes.code'),
            'name_en' => __('core.tax.attributes.name_en'),
            'name_fr' => __('core.tax.attributes.name_fr'),
            'kind' => __('core.tax.attributes.kind'),
            'rate' => __('core.tax.attributes.rate'),
            'effective_from' => __('core.tax.attributes.effective_from'),
            'fiscal_code' => __('core.tax.attributes.fiscal_code'),
        ];
    }
}
