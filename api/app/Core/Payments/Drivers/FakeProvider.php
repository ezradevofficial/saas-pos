<?php

namespace App\Core\Payments\Drivers;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\HostedCheckout;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;

/**
 * Local and test payments (NFR-06: never in a real environment; see
 * `payments.allow_fake`). Deterministic by the phone number's last three
 * digits: `000` is refused at once, `111` never answers (times out),
 * `222` is declined by the customer; anything else is pending after the
 * push and succeeds when checked. Refunds and checks of manual codes
 * succeed. As a hosted card checkout (SUB-04): the "checkout page" sends
 * the payer straight back to the app, saving a card always works (label
 * "Card •••• 4242"), and charges succeed.
 */
class FakeProvider implements HostedCheckout, PaymentProvider
{
    public function name(): string
    {
        return 'fake';
    }

    /** Any currency, unless a test declares a provider's rules (`payments.fake_currencies`, e.g. M-Pesa's whole shillings). */
    public function currencies(): array
    {
        return (array) config('payments.fake_currencies', []);
    }

    public function canPush(): bool
    {
        return true;
    }

    public function initiate(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        if (str_ends_with((string) $intent->phone, '000')) {
            return ProviderResult::failed('fake_refused', __('payments.errors.provider_refused'));
        }

        if ($intent->mode === 'checkout') {
            return ProviderResult::pending('fake-req-'.$intent->id, 'fake-chk-'.$intent->id, ['redirect_url' => rtrim((string) config('app.frontend_url'), '/').'/settings/subscription?payment='.$intent->id]);
        }

        return ProviderResult::pending('fake-req-'.$intent->id, 'fake-chk-'.$intent->id);
    }

    public function setupUrl(string $reference, string $returnUrl): string
    {
        return $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'card_setup='.rawurlencode($reference);
    }

    public function completeSetup(string $reference): ?array
    {
        return ['token' => 'fake_tok_'.hash('sha256', $reference), 'label' => 'Card •••• 4242'];
    }

    public function charge(PaymentIntent $intent, PaymentMethod $method, string $token): ProviderResult
    {
        return ProviderResult::succeeded(self::receipt($intent), ['result_code' => '0'], $intent->amount_minor);
    }

    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return match (true) {
            str_ends_with((string) $intent->phone, '111') => ProviderResult::pending(),
            str_ends_with((string) $intent->phone, '222') => new ProviderResult('cancelled', resultCode: 'fake_declined', message: __('payments.errors.declined')),
            default => ProviderResult::succeeded(self::receipt($intent), ['result_code' => '0'], $intent->amount_minor),
        };
    }

    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::succeeded(self::receipt($refund));
    }

    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::succeeded($intent->provider_receipt, [], $intent->amount_minor);
    }

    private static function receipt(PaymentIntent $intent): string
    {
        return 'FK'.strtoupper(substr(str_replace('-', '', (string) $intent->id), -8));
    }
}
