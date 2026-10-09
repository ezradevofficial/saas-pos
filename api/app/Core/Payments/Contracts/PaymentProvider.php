<?php

namespace App\Core\Payments\Contracts;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;

/**
 * A payment provider adapter (concept note 7.1 build note): every provider
 * is a plug-in with the same four operations. Adapters read the method's
 * settings and secrets (MD-04) and never store anything themselves: the
 * caller (PaymentIntents) applies the ProviderResult to the intent.
 *
 * An adapter throws ProviderUnavailable when the provider could not be
 * reached or answered with a server error (nothing is known), and returns
 * a `failed` result when the provider refused the request.
 */
interface PaymentProvider
{
    /** The adapter's key in PaymentProviderRegistry (`mpesa_daraja`, `manual`, ...). */
    public function name(): string;

    /**
     * Currencies the provider takes, with whether amounts must be whole
     * units (M-Pesa takes whole shillings). Empty means any currency.
     *
     * @return array<string, bool> currency => whole units only
     */
    public function currencies(): array;

    /** Whether the provider can push a payment request to the customer's phone. */
    public function canPush(): bool;

    /** Ask for the money (an STK push): pending with the provider's ids, or failed. */
    public function initiate(PaymentIntent $intent, PaymentMethod $method): ProviderResult;

    /** Ask the provider where an intent stands (an STK query), when its answer is late. */
    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult;

    /** Pay a refund back (B2C) for $original: pending (the result comes later) or failed. */
    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult;

    /**
     * Check a payment the cashier confirmed by hand (a manual M-Pesa
     * code): `succeeded` with the provider's amount when known now,
     * `pending` when the answer comes later by callback, `failed` when the
     * provider cannot check.
     */
    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult;
}
