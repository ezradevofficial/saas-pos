<?php

namespace App\Core\Payments\Daraja;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\CallbackTokens;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\ProviderResult;

/**
 * M-Pesa Kenya through Safaricom Daraja (concept note 7.1: direct, no
 * aggregator). KES only, in whole shillings.
 *
 * - initiate: STK push; the result comes to the `stk` callback.
 * - status: STK query, for a push whose callback is late.
 * - refund: B2C payment to the phone the original STK push was sent to;
 *   the result comes to `b2c-result` (or `b2c-timeout`).
 * - reconcile: transaction status query for a code the cashier typed; the
 *   result comes to `status-result`.
 *
 * Daraja STK result codes: 0 paid; 1032 cancelled by the customer; 1037
 * and 1019 not reached or expired (timeout); anything else failed.
 */
class MpesaDarajaProvider implements PaymentProvider
{
    public const STILL_PROCESSING = '500.001.1001';

    private const CANCELLED = ['1032'];

    private const TIMED_OUT = ['1037', '1019'];

    public function __construct(private readonly CallbackTokens $callbacks) {}

    public function name(): string
    {
        return 'mpesa_daraja';
    }

    public function currencies(): array
    {
        return ['KES' => true];
    }

    public function canPush(): bool
    {
        return true;
    }

    public function client(PaymentMethod $method): DarajaClient
    {
        return new DarajaClient(DarajaCredentials::of($method));
    }

    public function initiate(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        $answer = $this->client($method)->stkPush(
            (string) $intent->phone,
            intdiv($intent->amount_minor, 100),
            (string) $intent->account_reference,
            (string) __('payments.daraja.description'),
            $this->callbacks->url($method, 'stk'),
        );

        if ((string) ($answer['ResponseCode'] ?? '') === '0' && filled($answer['CheckoutRequestID'] ?? null)) {
            return ProviderResult::pending((string) ($answer['MerchantRequestID'] ?? ''), (string) $answer['CheckoutRequestID']);
        }

        return ProviderResult::failed(self::code($answer), self::message($answer));
    }

    public function status(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        if (blank($intent->provider_checkout_id)) {
            return ProviderResult::pending();
        }

        $answer = $this->client($method)->stkQuery((string) $intent->provider_checkout_id);

        if (($answer['errorCode'] ?? null) === self::STILL_PROCESSING) {
            return ProviderResult::pending();
        }

        if (! array_key_exists('ResultCode', $answer)) {
            return ProviderResult::pending();
        }

        // The query tells the outcome but not the receipt: a success waits
        // for the callback (or a C2B confirmation) to bring the receipt.
        return self::fromResultCode((string) $answer['ResultCode'], (string) ($answer['ResultDesc'] ?? ''), null, null);
    }

    public function refund(PaymentIntent $refund, PaymentIntent $original, PaymentMethod $method): ProviderResult
    {
        $credentials = DarajaCredentials::of($method);

        if (! $credentials->hasInitiator()) {
            return ProviderResult::failed('initiator_missing', __('payments.errors.initiator_missing'));
        }

        if (blank($original->phone)) {
            return ProviderResult::failed('phone_unknown', __('payments.errors.phone_unknown'));
        }

        $answer = $this->client($method)->b2c(
            (string) $refund->id,
            (string) $original->phone,
            intdiv($refund->amount_minor, 100),
            (string) __('payments.daraja.refund_remarks'),
            $this->callbacks->url($method, 'b2c-result'),
            $this->callbacks->url($method, 'b2c-timeout'),
            (string) $original->provider_receipt,
        );

        if ((string) ($answer['ResponseCode'] ?? '') === '0' && filled($answer['ConversationID'] ?? null)) {
            return ProviderResult::pending((string) ($answer['OriginatorConversationID'] ?? $refund->id), (string) $answer['ConversationID']);
        }

        return ProviderResult::failed(self::code($answer), self::message($answer));
    }

    public function reconcile(PaymentIntent $intent, PaymentMethod $method): ProviderResult
    {
        if (! DarajaCredentials::of($method)->hasInitiator()) {
            return ProviderResult::failed('initiator_missing', __('payments.errors.initiator_missing'));
        }

        $answer = $this->client($method)->transactionStatus(
            (string) $intent->provider_receipt,
            $this->callbacks->url($method, 'status-result'),
            $this->callbacks->url($method, 'status-timeout'),
        );

        if ((string) ($answer['ResponseCode'] ?? '') === '0' && filled($answer['ConversationID'] ?? null)) {
            return ProviderResult::pending((string) ($answer['OriginatorConversationID'] ?? ''), (string) $answer['ConversationID']);
        }

        return ProviderResult::failed(self::code($answer), self::message($answer));
    }

    /** An STK result code as a provider result. */
    public static function fromResultCode(string $code, string $description, ?string $receipt, ?int $amountMinor): ProviderResult
    {
        $data = ['result_code' => $code, 'result_desc' => mb_substr($description, 0, 200)];

        return match (true) {
            $code === '0' => ProviderResult::succeeded($receipt, $data, $amountMinor),
            in_array($code, self::CANCELLED, true) => new ProviderResult('cancelled', resultCode: $code, message: __('payments.errors.declined'), data: $data),
            in_array($code, self::TIMED_OUT, true) => new ProviderResult('timeout', resultCode: $code, message: __('payments.errors.not_reached'), data: $data),
            default => ProviderResult::failed($code, __('payments.errors.provider_refused'), $data),
        };
    }

    private static function code(array $answer): string
    {
        return mb_substr((string) ($answer['ResponseCode'] ?? $answer['errorCode'] ?? 'unknown'), 0, 40);
    }

    /** Daraja's own text is not shown to the till (it may echo request values); a fixed message is. */
    private static function message(array $answer): string
    {
        return __('payments.errors.provider_refused');
    }
}
