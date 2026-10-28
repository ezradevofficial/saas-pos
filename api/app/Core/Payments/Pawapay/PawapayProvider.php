<?php

namespace App\Core\Payments\Pawapay;

use App\Core\Currency\CurrencyDecimals;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Contracts\PresetsRequestId;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;
use App\Core\Payments\ProviderUnavailable;

/**
 * DRC mobile money through pawaPay's v2 API (Vodacom M-Pesa, Airtel and
 * Orange; CDF and USD). Used by the platform's billing and by each DRC
 * business's till (the business's own API token).
 *
 * - initiate: validates the phone with predict-provider (when pawaPay
 *   answers), then POST /v2/deposits. The depositId is a UUIDv4 derived
 *   from the intent id (PresetsRequestId), so a retry or a lost answer
 *   never creates a second deposit (DUPLICATE_IGNORED is the same
 *   deposit).
 * - status: GET /v2/deposits/{id}. COMPLETED is a success only when the
 *   currency and the amount are exactly the intent's (NFR-06); the caller
 *   (PaymentIntents::apply) turns another amount into `amount_mismatch`.
 * - refund: POST /v2/refunds for the original deposit; the result comes
 *   to the refunds callback.
 *
 * Phones and tokens never reach logs: errors carry fixed messages.
 */
class PawapayProvider implements PaymentProvider, PresetsRequestId
{
    private const CANCELLED = ['PAYMENT_NOT_APPROVED'];

    public function __construct(private readonly CurrencyDecimals $decimals) {}

    public function name(): string
    {
        return 'pawapay';
    }

    /** CDF and USD; the decimals are the platform's (CDF 0, USD 2), not whole-unit only. */
    public function currencies(): array
    {
        return ['CDF' => false, 'USD' => false];
    }

    public function canPush(): bool
    {
        return true;
    }

    /** A UUIDv4 derived from $id: the same input always gives the same id. */
    public static function idFor(string $id): string
    {
        $bytes = substr(hash('sha256', 'pawapay:'.$id, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    public function requestIdFor(string $intentId): string
    {
        return self::idFor($intentId);
    }

    public function client(PaymentMethod $method): PawapayClient
    {
        return new PawapayClient(PawapayCredentials::of($method));
    }

    /** The pawaPay provider code of one of our provider keys, or null. */
    public static function providerCode(?string $provider): ?string
    {
        $code = config("payments.pawapay.providers.{$provider}");

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** Digits only, with the DRC country code (0813456789 and 813456789 become 243813456789); null when it can not be a DRC mobile number. */
    public static function msisdn(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);
        $cc = (string) config('payments.pawapay.country_code', '243');

        if (preg_match('/^0(\d{9})$/', $digits, $m) === 1 || preg_match('/^(\d{9})$/', $digits, $m) === 1) {
            return $cc.$m[1];
        }

        return preg_match('/^'.$cc.'\d{9}$/', $digits) === 1 ? $digits : null;
    }

    public function initiate(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        $code = self::providerCode($intent->provider);
        $phone = self::msisdn($intent->phone);

        if ($code === null || $phone === null) {
            return ProviderResult::failed('phone_invalid', __('payments.errors.phone_invalid'));
        }

        $client = $this->client($method);

        // Phone validation: pawaPay tells whether the number is a mobile money number and of which provider.
        try {
            $predicted = $client->predictProvider($phone);

            if ($predicted === null) {
                return ProviderResult::failed('phone_invalid', __('payments.errors.phone_invalid'));
            }

            if (($predicted['provider'] ?? null) !== $code) {
                return ProviderResult::failed('phone_provider_mismatch', __('payments.errors.phone_provider_mismatch'));
            }

            $phone = (string) $predicted['phoneNumber'];
        } catch (ProviderUnavailable) {
            // The prediction is a convenience; the deposit itself decides.
        }

        $answer = $client->deposit([
            'depositId' => self::idFor((string) $intent->id),
            'amount' => PawapayAmounts::format($intent->amount_minor, $this->decimals->for($intent->currency)),
            'currency' => $intent->currency,
            'payer' => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => $phone, 'provider' => $code]],
            'clientReferenceId' => (string) $intent->account_reference,
            'customerMessage' => (string) __('payments.pawapay.description'),
        ]);

        return match ($answer['status'] ?? null) {
            // DUPLICATE_IGNORED: this deposit id was already sent (a retry): it is the same deposit.
            'ACCEPTED', 'DUPLICATE_IGNORED' => ProviderResult::pending(self::idFor((string) $intent->id)),
            'REJECTED' => ProviderResult::failed(self::code($answer), __('payments.errors.provider_refused')),
            default => throw new ProviderUnavailable,
        };
    }

    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        $answer = $this->client($method)->depositStatus(self::idFor((string) $intent->id));

        if (($answer['status'] ?? null) === 'NOT_FOUND') {
            return ProviderResult::failed('not_found', __('payments.errors.provider_refused'));
        }

        $data = $answer['data'] ?? null;

        if (($answer['status'] ?? null) !== 'FOUND' || ! is_array($data)) {
            return ProviderResult::pending();
        }

        return $this->fromDeposit($intent, $data);
    }

    /** A pawaPay deposit object (status response or callback body) as a result. */
    public function fromDeposit(PaymentIntent $intent, array $deposit): ProviderResult
    {
        return match ($deposit['status'] ?? null) {
            'COMPLETED' => $this->completed($intent, $deposit),
            'FAILED' => self::failure($deposit),
            default => ProviderResult::pending(),
        };
    }

    private function completed(PaymentIntent $intent, array $deposit): ProviderResult
    {
        $data = ['result_code' => 'COMPLETED'];

        if (($deposit['currency'] ?? null) !== $intent->currency) {
            return ProviderResult::failed('currency_mismatch', __('payments.errors.amount_mismatch'), $data);
        }

        // An amount that is not exactly the intent's (or not readable) is never a success: -1 can not equal an amount.
        $amount = PawapayAmounts::parse($deposit['amount'] ?? null, $this->decimals->for($intent->currency)) ?? -1;
        $receipt = is_scalar($deposit['providerTransactionId'] ?? null) ? mb_substr((string) $deposit['providerTransactionId'], 0, 100) : null;

        return ProviderResult::succeeded($receipt ?? self::idFor((string) $intent->id), $data, $amount);
    }

    public static function failure(array $object): ProviderResult
    {
        $code = mb_substr((string) ($object['failureReason']['failureCode'] ?? 'OTHER_ERROR'), 0, 40);
        $data = ['result_code' => $code];

        return in_array($code, self::CANCELLED, true)
            ? new ProviderResult('cancelled', resultCode: $code, message: __('payments.errors.declined'), data: $data)
            : ProviderResult::failed($code, __('payments.errors.provider_refused'), $data);
    }

    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult
    {
        $answer = $this->client($method)->refund([
            'refundId' => self::idFor((string) $refund->id),
            'depositId' => self::idFor((string) $original->id),
            'amount' => PawapayAmounts::format($refund->amount_minor, $this->decimals->for($refund->currency)),
            'currency' => $refund->currency,
        ]);

        return match ($answer['status'] ?? null) {
            'ACCEPTED', 'DUPLICATE_IGNORED' => ProviderResult::pending(self::idFor((string) $refund->id)),
            'REJECTED' => ProviderResult::failed(self::code($answer), __('payments.errors.provider_refused')),
            default => throw new ProviderUnavailable,
        };
    }

    /** The refund object of GET /v2/refunds/{id} as a result (null: not final or unknown). */
    public function refundResult(PaymentIntent $refund, PaymentMethod $method): ProviderResult
    {
        $answer = $this->client($method)->refundStatus(self::idFor((string) $refund->id));
        $data = $answer['data'] ?? null;

        if (($answer['status'] ?? null) !== 'FOUND' || ! is_array($data)) {
            return ProviderResult::pending();
        }

        return match ($data['status'] ?? null) {
            'COMPLETED' => ($data['currency'] ?? null) === $refund->currency
                && PawapayAmounts::parse($data['amount'] ?? null, $this->decimals->for($refund->currency)) === $refund->amount_minor
                ? ProviderResult::succeeded(is_scalar($data['providerTransactionId'] ?? null) ? mb_substr((string) $data['providerTransactionId'], 0, 100) : null, ['result_code' => 'COMPLETED'], $refund->amount_minor)
                : ProviderResult::failed('amount_mismatch', __('payments.errors.amount_mismatch')),
            'FAILED' => self::failure($data),
            default => ProviderResult::pending(),
        };
    }

    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        return ProviderResult::failed('check_unsupported', __('payments.errors.check_unsupported'));
    }

    private static function code(array $answer): string
    {
        return mb_substr((string) ($answer['failureReason']['failureCode'] ?? $answer['status'] ?? 'unknown'), 0, 40);
    }
}
