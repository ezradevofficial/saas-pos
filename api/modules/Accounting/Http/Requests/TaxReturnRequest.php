<?php

namespace Modules\Accounting\Http\Requests;

use Carbon\CarbonImmutable;
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

    /** No period given: last month in the company's time zone, as the page opens on (a return is for a past month). */
    protected function prepareForValidation(): void
    {
        if ($this->filled('from') || $this->filled('to')) {
            return;
        }

        $company = $this->route('company');
        $zone = is_object($company) && ! empty($company->timezone) ? $company->timezone : 'UTC';
        $month = CarbonImmutable::now($zone)->subMonthNoOverflow();
        $this->merge(['from' => $month->startOfMonth()->toDateString(), 'to' => $month->endOfMonth()->toDateString()]);
    }

    public function rules(): array
    {
        return [
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
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
