<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** GET companies/{company}/accounting/reports/*: dates, dimensions, the account (GL), the party, side and currency (statements, ageing) (ACC-11, ACC-17). */
class ReportRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.report.view';
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'account' => ['sometimes', 'uuid', $this->ofCompany('acc_accounts')],
            'cost_centre' => ['sometimes', 'uuid', $this->ofCompany('cost_centres')],
            'party' => ['sometimes', 'uuid', Rule::exists('parties', 'id')],
            'kind' => ['sometimes', 'string', Rule::in(['customer', 'supplier'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'format' => ['sometimes', 'string', Rule::in(['pdf'])],
        ];
    }
}
