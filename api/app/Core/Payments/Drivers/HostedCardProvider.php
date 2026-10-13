<?php

namespace App\Core\Payments\Drivers;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\HostedCheckout;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;
use App\Core\Payments\ProviderUnavailable;

/**
 * SUB-04: card payments through a generic hosted checkout (the card
 * aggregator is owner input). Unavailable until configured; the fake
 * driver stands in locally.
 */
class HostedCardProvider extends UnconfiguredProvider implements HostedCheckout
{
    public function name(): string
    {
        return 'card_hosted';
    }

    public function canPush(): bool
    {
        return false;
    }

    public function setupUrl(string $reference, string $returnUrl): string
    {
        throw new ProviderUnavailable(__('payments.errors.provider_not_configured'));
    }

    public function completeSetup(string $reference): ?array
    {
        throw new ProviderUnavailable(__('payments.errors.provider_not_configured'));
    }

    public function charge(PaymentIntent $intent, PaymentMethod $method, string $token): ProviderResult
    {
        return ProviderResult::failed('provider_not_configured', __('payments.errors.provider_not_configured'));
    }
}
