<?php

namespace App\Core\Payments\Paystack;

use App\Core\Billing\Operator\Operator;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;
use App\Core\Payments\ProviderUnavailable;
use App\Core\Support\OutboundHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Kenya subscription billing through Paystack (M-Pesa and card on its hosted
 * page, saved-card renewals). Amounts are in minor units, exactly as
 * Paystack takes them (KES cents, USD cents). Our reference is the payment
 * intent's id.
 *
 * - checkout(): POST /transaction/initialize; the payer is sent to
 *   `authorization_url` (pending with `redirect_url`).
 * - status(): GET /transaction/verify/{reference}. `success` is a success
 *   only for exactly the intent's currency and amount (NFR-06); the amount
 *   is handed back so PaymentIntents::apply refuses a different one.
 * - chargeAuthorization(): a saved card charged without the payer, then
 *   verified the same way.
 *
 * A reusable card authorization found on a successful verify is held for an
 * hour, encrypted, for BillingPayments to save as a payment method (last4,
 * brand and expiry only; no card number ever reaches us). Errors carry fixed
 * messages: never the body, the key, the email or the authorization code.
 */
class PaystackProvider implements PaymentProvider
{
    public const AUTH_CACHE = 'paystack-auth:';

    public function name(): string
    {
        return 'paystack';
    }

    public function currencies(): array
    {
        return ['KES' => false, 'USD' => false];
    }

    public function canPush(): bool
    {
        return false;
    }

    /** Whether the operator has a Paystack key (its method's secret, else PAYSTACK_SECRET_KEY). */
    public static function configured(?PaymentMethod $method = null): bool
    {
        return self::secretKey($method) !== '';
    }

    public static function secretKey(?PaymentMethod $method): string
    {
        $key = (string) (((array) ($method?->secrets ?? []))['api_key'] ?? '');

        if ($key === '' && ($method === null || app(Operator::class)->is((string) $method->tenant_id))) {
            $key = (string) config('payments.paystack.secret_key', '');
        }

        return $key;
    }

    /** Every key a Paystack webhook of the operator may be signed with. */
    public static function webhookKeys(): array
    {
        $keys = [(string) config('payments.paystack.secret_key', '')];

        foreach (PaymentMethod::query()->where('provider', 'card_aggregator')->get() as $method) {
            $keys[] = (string) (((array) ($method->secrets ?? []))['api_key'] ?? '');
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /** The signature Paystack sends in `x-paystack-signature`: HMAC SHA512 of the raw body. */
    public static function signatureOf(string $body, string $key): string
    {
        return hash_hmac('sha512', $body, $key);
    }

    /** The checkout page for $email: pending with `redirect_url`. */
    public function checkout(PaymentIntent $intent, PaymentMethod $method, string $email): ProviderResult
    {
        $answer = $this->call($method, 'post', '/transaction/initialize', [
            'email' => $email,
            'amount' => $intent->amount_minor,
            'currency' => $intent->currency,
            'reference' => (string) $intent->id,
            'callback_url' => (string) config('payments.paystack.callback_url'),
        ]);

        $url = $answer['data']['authorization_url'] ?? null;

        if (($answer['status'] ?? false) === true && is_string($url) && str_starts_with($url, 'https://')) {
            return ProviderResult::pending(is_string($answer['data']['access_code'] ?? null) ? $answer['data']['access_code'] : null, (string) $intent->id, ['redirect_url' => $url]);
        }

        return ProviderResult::failed('initialize_failed', __('payments.errors.provider_refused'));
    }

    /** A saved card charged without the payer; the outcome is confirmed by verify. */
    public function chargeAuthorization(PaymentIntent $intent, PaymentMethod $method, string $authorizationCode, string $email): ProviderResult
    {
        $answer = $this->call($method, 'post', '/transaction/charge_authorization', [
            'authorization_code' => $authorizationCode,
            'email' => $email,
            'amount' => $intent->amount_minor,
            'currency' => $intent->currency,
            'reference' => (string) $intent->id,
        ]);

        if (($answer['status'] ?? false) !== true) {
            return ProviderResult::failed('charge_failed', __('payments.errors.provider_refused'));
        }

        return match ($answer['data']['status'] ?? null) {
            'failed', 'reversed' => ProviderResult::failed((string) $answer['data']['status'], __('payments.errors.provider_refused')),
            'success' => $this->status($intent, $method),
            default => ProviderResult::pending(null, (string) $intent->id),
        };
    }

    public function initiate(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        // A checkout needs the payer's email, which only the billing code has (checkout()).
        return ProviderResult::failed('provider_not_configured', __('payments.errors.provider_not_configured'));
    }

    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        $answer = $this->call($method, 'get', '/transaction/verify/'.rawurlencode((string) $intent->id));
        $data = $answer['data'] ?? null;

        if (($answer['status'] ?? false) !== true || ! is_array($data)) {
            // Unknown to Paystack: the payer has not reached the page yet.
            return ProviderResult::pending();
        }

        return match ($data['status'] ?? null) {
            'success' => $this->success($intent, $data),
            'failed', 'reversed' => ProviderResult::failed(mb_substr((string) $data['status'], 0, 40), __('payments.errors.provider_refused')),
            default => ProviderResult::pending(),
        };
    }

    private function success(PaymentIntent $intent, array $data): ProviderResult
    {
        if (($data['currency'] ?? null) !== $intent->currency || ($data['reference'] ?? null) !== (string) $intent->id) {
            return ProviderResult::failed('currency_mismatch', __('payments.errors.amount_mismatch'));
        }

        // Exact: a missing or non-integer amount can never equal the intent's.
        $amount = is_int($data['amount'] ?? null) ? $data['amount'] : -1;
        $this->keepAuthorization($intent, $data['authorization'] ?? null);

        return ProviderResult::succeeded(is_scalar($data['id'] ?? null) ? mb_substr((string) $data['id'], 0, 100) : (string) $intent->id, ['result_code' => 'success', 'channel' => is_string($data['channel'] ?? null) ? $data['channel'] : null], $amount);
    }

    /** A reusable card kept for an hour, encrypted, for BillingPayments to save (no card number: last4, brand, expiry). */
    private function keepAuthorization(PaymentIntent $intent, mixed $authorization): void
    {
        if (! is_array($authorization) || ($authorization['reusable'] ?? false) !== true || ($authorization['channel'] ?? null) !== 'card' || ! is_string($authorization['authorization_code'] ?? null)) {
            return;
        }

        Cache::put(self::AUTH_CACHE.$intent->id, Crypt::encryptString((string) json_encode([
            'code' => $authorization['authorization_code'],
            'last4' => (string) ($authorization['last4'] ?? ''),
            'brand' => (string) ($authorization['brand'] ?? $authorization['card_type'] ?? ''),
            'exp_month' => (string) ($authorization['exp_month'] ?? ''),
            'exp_year' => (string) ($authorization['exp_year'] ?? ''),
        ])), 3600);
    }

    /** @return array{code: string, last4: string, brand: string, exp_month: string, exp_year: string}|null */
    public static function takeAuthorization(string $intentId): ?array
    {
        $sealed = Cache::pull(self::AUTH_CACHE.$intentId);

        if (! is_string($sealed)) {
            return null;
        }

        $card = json_decode(Crypt::decryptString($sealed), true);

        return is_array($card) && filled($card['code'] ?? null) ? $card : null;
    }

    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('payout_unsupported', __('payments.errors.payout_unsupported'));
    }

    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('check_unsupported', __('payments.errors.check_unsupported'));
    }

    private function call(PaymentMethod $method, string $verb, string $path, array $body = []): array
    {
        $key = self::secretKey($method);

        if ($key === '') {
            throw new ProviderUnavailable(__('payments.errors.provider_not_configured'));
        }

        try {
            $http = OutboundHttp::client((string) config('payments.paystack.base_url'), (int) config('payments.paystack.timeout', 20))->withToken($key);
            /** @var Response $response */
            $response = $verb === 'post' ? $http->post($path, $body) : $http->get($path);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable(previous: $e);
        }

        if ($response->status() === 401) {
            throw new ProviderUnavailable(__('payments.errors.provider_auth'));
        }

        // Paystack answers 400/404 with a JSON body for refused or unknown transactions; 5xx says nothing.
        if ($response->serverError() || ($response->status() !== 404 && ! $response->successful() && $response->status() !== 400)) {
            throw new ProviderUnavailable;
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
