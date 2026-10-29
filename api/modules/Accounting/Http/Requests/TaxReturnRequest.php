<?php

namespace Modules\Accounting\Http\Requests;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** ACC-36: GET companies/{company}/accounting/reports/tax-return[/entries|/export]: the period and, for the documents behind a row, the row. */
class TaxReturnRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.report.view';
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'direction' => ['sometimes', Rule::in(['output', 'input'])],
            'tax_code' => ['sometimes', 'string', fn (string $attribute, mixed $value, Closure $fail) => $value === 'none' || $this->taxCodeExists((string) $value) ? null : $fail(__('validation.exists', ['attribute' => $attribute]))],
            'rate' => ['sometimes', 'string', 'regex:/^(none|\d{1,3}(\.\d{1,4})?)$/'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    private function taxCodeExists(string $id): bool
    {
        return Str::isUuid($id) && DB::table('tax_codes')->where('id', $id)->where('company_id', $this->company()->id)->exists();
    }
}
