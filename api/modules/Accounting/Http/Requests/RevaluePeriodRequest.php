<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/periods/{acc_period}/revalue (ACC-15). */
class RevaluePeriodRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.period.close';
    }
}
