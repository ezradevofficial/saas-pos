<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/periods/{acc_period}/reopen, with a reason (ACC-08). */
class ReopenPeriodRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.period.reopen';
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
