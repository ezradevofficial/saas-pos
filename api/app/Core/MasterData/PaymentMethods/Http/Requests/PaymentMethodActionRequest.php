<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

/** TEN-06: archive or restore a payment method (`core.payment_method.archive`). No body. */
class PaymentMethodActionRequest extends PaymentMethodRequest
{
    protected bool $edits = true;

    protected string $action = 'archive';
}
