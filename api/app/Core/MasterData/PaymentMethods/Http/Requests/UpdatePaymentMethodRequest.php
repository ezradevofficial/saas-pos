<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;

/**
 * MD-04: rename a payment method, change a cash method's currency, set or
 * clear provider settings and secrets (a null value clears a key), switch
 * it on or off (`core.payment_method.edit`). The type and provider are set
 * at creation.
 */
class UpdatePaymentMethodRequest extends PaymentMethodRequest
{
    use GuardsProviderConfig;

    protected bool $edits = true;

    protected function configuredMethod(): ?PaymentMethod
    {
        return $this->paymentMethod();
    }

    public function rules(): array
    {
        return PaymentMethodRules::rules($this->paymentMethod());
    }

    public function after(): array
    {
        return [fn ($validator) => PaymentMethodRules::validate($validator, $this->all(), $this->paymentMethod())];
    }

    public function messages(): array
    {
        return PaymentMethodRules::messages();
    }

    public function attributes(): array
    {
        return PaymentMethodRules::attributes();
    }
}
