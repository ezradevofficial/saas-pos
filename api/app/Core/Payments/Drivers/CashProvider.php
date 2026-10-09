<?php

namespace App\Core\Payments\Drivers;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;

/** Cash and other methods with no provider: nothing to ask, the money is in the drawer. */
class CashProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'cash';
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
        return ProviderResult::succeeded();
    }

    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::succeeded();
    }

    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::succeeded();
    }

    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::succeeded(amountMinor: $intent->amount_minor);
    }
}
