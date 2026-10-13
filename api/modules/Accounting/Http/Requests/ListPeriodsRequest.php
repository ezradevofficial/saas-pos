<?php

namespace Modules\Accounting\Http\Requests;

/** GET companies/{company}/accounting/periods (ACC-08), `?year=` (the fiscal year, created when missing). */
class ListPeriodsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.period.view';
    }

    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ];
    }
}
