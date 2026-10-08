<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;

/**
 * MD-04: a new payment method (`core.payment_method.create`). Cash needs an
 * active currency; mobile money and card need a provider of their type.
 */
class StorePaymentMethodRequest extends CompanyPaymentMethodRequest
{
    use GuardsProviderConfig;

    protected bool $edits = true;

    protected string $action = 'create';

    protected function configuredMethod(): ?PaymentMethod
    {
        return null;
    }

    public function rules(): array
    {
        return PaymentMethodRules::rules(null);
    }

    public function after(): array
    {
        return [fn ($validator) => PaymentMethodRules::validate($validator, $this->all(), null)];
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
