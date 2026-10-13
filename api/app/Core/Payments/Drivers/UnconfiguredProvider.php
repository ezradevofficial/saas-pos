<?php

namespace App\Core\Payments\Drivers;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;
use App\Core\Payments\ProviderUnavailable;

/**
 * SUB-04: a provider whose merchant API is owner input (concept note 7.1:
 * the aggregator is not chosen yet; docs/integrations.md lists what is
 * needed). Every request reports the provider unavailable, so nothing is
 * ever confirmed by mistake; the fake driver stands in locally.
 */
abstract class UnconfiguredProvider implements PaymentProvider
{
    public function currencies(): array
    {
        return [];
    }

    public function canPush(): bool
    {
        return true;
    }

    public function initiate(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('provider_not_configured', __('payments.errors.provider_not_configured'));
    }

    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        throw new ProviderUnavailable(__('payments.errors.provider_not_configured'));
    }

    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('provider_not_configured', __('payments.errors.provider_not_configured'));
    }

    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('provider_not_configured', __('payments.errors.provider_not_configured'));
    }
}
