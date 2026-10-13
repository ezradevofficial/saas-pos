<?php

namespace App\Core\Payments\Contracts;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;

/**
 * SUB-04: a card provider with a hosted checkout. The payer types card
 * details on the provider's page only; the platform keeps the provider's
 * token (never card data) to charge renewals.
 *
 * - setupUrl(): the page where the payer saves a card; the provider sends
 *   them back to $returnUrl with $reference.
 * - completeSetup(): the token and a label ("Card •••• 4242") of the card
 *   saved under $reference, or null when it was not saved.
 * - initiate() (PaymentProvider) for a checkout returns `pending` with
 *   `data.redirect_url`, the page that takes the payment.
 * - charge(): a saved card charged without the payer.
 */
interface HostedCheckout
{
    public function setupUrl(string $reference, string $returnUrl): string;

    /** @return array{token: string, label: string}|null */
    public function completeSetup(string $reference): ?array;

    public function charge(PaymentIntent $intent, PaymentMethod $method, string $token): ProviderResult;
}
