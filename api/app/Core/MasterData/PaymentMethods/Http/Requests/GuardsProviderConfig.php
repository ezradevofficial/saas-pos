<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;

/**
 * MD-04, RBAC-01: provider credentials are a fraud path, so changing a
 * method's `settings`, `secrets` or `provider`, or switching on a mobile
 * money or card method, needs `core.payment_method.configure` at the
 * company on top of the request's own permission (403 otherwise).
 */
trait GuardsProviderConfig
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return ! $this->touchesProviderConfig() || $this->user()->can(PaymentMethod::CONFIGURE, $this->targetCompany());
    }

    /** The method being changed, or null for a new one. */
    abstract protected function configuredMethod(): ?PaymentMethod;

    private function touchesProviderConfig(): bool
    {
        $method = $this->configuredMethod();

        if ($this->has('settings') || $this->has('secrets')) {
            return true;
        }

        $provider = $this->input('provider');

        if ($method === null ? $provider !== null : ($this->has('provider') && $provider !== $method->provider)) {
            return true;
        }

        $type = $method?->type ?? $this->input('type');
        $switchesOn = $this->has('active') && $this->boolean('active') && ! ($method?->active ?? false);

        return $switchesOn && in_array($type, PaymentMethod::PROVIDER_TYPES, true);
    }
}
