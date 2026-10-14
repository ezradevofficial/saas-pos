<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/periods/{acc_period}/close (ACC-08), with `revalue` (ACC-15). */
class ClosePeriodRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.period.close';
    }

    public function rules(): array
    {
        return [
            'revalue' => ['sometimes', 'boolean'],
        ];
    }
}
