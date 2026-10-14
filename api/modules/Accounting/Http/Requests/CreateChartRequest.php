<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Chart\ChartTemplates;

/** POST companies/{company}/accounting/chart: the chart from a country template (ACC-01), once. */
class CreateChartRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.account.manage';
    }

    public function rules(): array
    {
        return [
            'template' => ['sometimes', 'string', Rule::in(app(ChartTemplates::class)->available())],
        ];
    }
}
