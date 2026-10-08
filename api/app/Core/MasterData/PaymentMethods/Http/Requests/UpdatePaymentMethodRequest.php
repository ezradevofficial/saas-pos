<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;

/**
 * MD-04: rename a payment method, change a cash method's currency, set or
 * clear provider settings and secrets (a null value clears a key), switch
 * it on or off (`core.payment_method.edit`). The type and provider are set
 * at creation.
 */
class UpdatePaymentMethodRequest extends PaymentMethodRequest
{
    use GuardsFieldRules, GuardsProviderConfig;

    /** RBAC-05: input refused when its field is hidden or read-only for the user. */
    protected string $fieldRulesResource = 'payment_method';

    /** @var array<string, list<string>> input key => field rule names it writes */
    protected array $fieldRulesInputs = ['name_en' => ['name'], 'name_fr' => ['name']];

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
