<?php

namespace App\Core\Payments\Drivers;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;

/**
 * Providers without an adapter yet (the aggregators of concept note 7.1):
 * the cashier confirms each payment with the provider's reference, and a
 * refund is paid back by hand. Nothing can be pushed or checked with the
 * provider: manual payments stay unverified until matched in the back
 * office.
 */
class ManualProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function currencies(): array
    {
        return [];
    }

    public function canPush(): bool
    {
        return false;
    }

    public function initiate(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('push_unsupported', __('payments.errors.push_unsupported'));
    }

    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return new ProviderResult($intent->status);
    }

    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('payout_unsupported', __('payments.errors.payout_unsupported'));
    }

    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('check_unsupported', __('payments.errors.check_unsupported'));
    }
}
