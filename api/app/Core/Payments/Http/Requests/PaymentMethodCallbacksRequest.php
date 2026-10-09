<?php

namespace App\Core\Payments\Http\Requests;

use App\Core\MasterData\PaymentMethods\Http\Requests\PaymentMethodRequest;
use App\Core\MasterData\PaymentMethods\PaymentMethod;

/**
 * A payment method's provider callbacks: read its callback URLs, rotate
 * their token, or register the C2B URLs with the provider. The URLs carry
 * the token that names the method, so every action needs
 * `core.payment_method.configure` at the method's company. No body.
 */
class PaymentMethodCallbacksRequest extends PaymentMethodRequest
{
    protected bool $edits = true;

    protected string $action = 'configure';

    public function target(): PaymentMethod
    {
        return $this->paymentMethod();
    }
}
