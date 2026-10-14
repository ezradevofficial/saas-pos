<?php

namespace Modules\Accounting\Http\Requests;

/** GET accounting/payments/{acc_payment}. */
class ShowPaymentRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.payment.view';
    }
}
