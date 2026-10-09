<?php

namespace App\Core\Payments\Daraja;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\ProviderResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * What Safaricom posts to a method's callback URLs (CallbackTokens),
 * already in the method's tenant. Each kind is checked for its shape (a
 * payload that is not Daraja's is refused with 422), applied once
 * (repeats find a final intent or a known receipt and change nothing) and
 * answered the way Daraja expects (`ResultCode` 0, "Accepted").
 *
 * Only what the platform needs is kept: result code and text, amount,
 * receipt and transaction time. Names and phone numbers in the payloads
 * are never stored (C2B MSISDNs are hashed by Safaricom anyway).
 */
class DarajaCallbacks
{
    public const ACCEPTED = ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];

    public function __construct(
        private readonly PaymentIntents $intents,
        private readonly CurrencyDecimals $decimals,
    ) {}

    /** @return array<string, scalar> the body to answer Safaricom with */
    public function handle(PaymentMethod $method, string $kind, array $payload): array
    {
        return match ($kind) {
            'stk' => $this->stk($method, $payload),
            'c2b-validate' => $this->validateC2b($method, $payload),
            'c2b-confirm' => $this->confirmC2b($method, $payload),
            'b2c-result' => $this->payoutResult($method, $payload),
            'b2c-timeout' => $this->payoutTimeout($method, $payload),
            'status-result' => $this->statusResult($method, $payload),
            'status-timeout' => $this->statusTimeout($method, $payload),
            default => throw new ApiException(404, 'not_found', __('payments.errors.callback_unknown')),
        };
    }

    private function stk(PaymentMethod $method, array $payload): array
    {
        $callback = $payload['Body']['stkCallback'] ?? null;
        $this->require(is_array($callback) && is_string($callback['CheckoutRequestID'] ?? null) && isset($callback['ResultCode']));

        $intent = $this->intentWhere($method, 'provider_checkout_id', $callback['CheckoutRequestID']);
        $items = collect($callback['CallbackMetadata']['Item'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['Name']))
            ->mapWithKeys(fn (array $item) => [$item['Name'] => $item['Value'] ?? null]);

        $code = (string) $callback['ResultCode'];
        $receipt = is_scalar($items->get('MpesaReceiptNumber')) ? strtoupper((string) $items->get('MpesaReceiptNumber')) : null;
        $amount = $items->has('Amount') ? $this->minor((string) $items->get('Amount'), $intent?->currency ?? 'KES') : null;

        if ($code === '0' && ($receipt === null || $amount === null || preg_match('/^[A-Z0-9]{6,20}$/', $receipt) !== 1)) {
            $this->require(false);
        }

        if ($intent === null) {
            Log::info('Daraja STK callback for an unknown checkout', ['method' => $method->id]);

            if ($code === '0') {
                // Money was taken for a push we cannot place: keep it for matching or refund.
                $this->keepUnmatched($method, $receipt, $amount, $items->get('TransactionDate'));
            }

            return self::ACCEPTED;
        }

        $result = MpesaDarajaProvider::fromResultCode($code, (string) ($callback['ResultDesc'] ?? ''), $receipt, $amount);
        $data = $result->data + array_filter([
            'amount' => $amount === null ? null : (string) $amount,
            'receipt' => $receipt,
            'transaction_date' => is_scalar($items->get('TransactionDate')) ? (string) $items->get('TransactionDate') : null,
        ]);

        $applied = $this->intents->apply($intent, new ProviderResult(
            $result->status,
            receipt: $result->receipt,
            resultCode: $result->resultCode,
            message: $result->message,
            amountMinor: $result->amountMinor,
            data: $data,
        ));

        // Paid, but the intent was already final (failed, cancelled, another
        // receipt) or the amount differs: the money is kept for the back office.
        if ($code === '0' && ($applied->status !== 'succeeded' || $applied->provider_receipt !== $receipt)) {
            $this->keepUnmatched($method, $receipt, $amount, $items->get('TransactionDate'));
        }

        return self::ACCEPTED;
    }

    /** A paid STK result no open intent took, as an unmatched receipt flagged `late_or_unmatched`. */
    private function keepUnmatched(PaymentMethod $method, string $receipt, int $amount, mixed $time): void
    {
        $this->intents->receive($method, [
            'receipt' => $receipt,
            'amount_minor' => $amount,
            'currency' => 'KES',
            'account_reference' => null,
            'shortcode' => null,
            'transacted_at' => $this->time($time),
            'data' => ['source' => 'stk'],
        ], match: false, flag: 'late_or_unmatched');
    }

    /**
     * C2B validation (only called when Safaricom has turned external
     * validation on for the shortcode): accept KES payments of a positive
     * amount while the method is on.
     */
    private function validateC2b(PaymentMethod $method, array $payload): array
    {
        $this->require(is_string($payload['TransID'] ?? null) && isset($payload['TransAmount']));

        try {
            $ok = $method->active && $this->minor((string) $payload['TransAmount'], 'KES') > 0;
        } catch (ApiException) {
            $ok = false;
        }

        // C2B00016: "Other error" in Daraja's validation codes.
        return $ok ? ['ResultCode' => '0', 'ResultDesc' => 'Accepted'] : ['ResultCode' => 'C2B00016', 'ResultDesc' => 'Rejected'];
    }

    private function confirmC2b(PaymentMethod $method, array $payload): array
    {
        $this->require(is_string($payload['TransID'] ?? null) && preg_match('/^[A-Za-z0-9]{6,20}$/', $payload['TransID']) === 1 && isset($payload['TransAmount']));

        $this->intents->receive($method, [
            'receipt' => $payload['TransID'],
            'amount_minor' => $this->minor((string) $payload['TransAmount'], 'KES'),
            'currency' => 'KES',
            'account_reference' => is_scalar($payload['BillRefNumber'] ?? null) && $payload['BillRefNumber'] !== '' ? mb_substr((string) $payload['BillRefNumber'], 0, 40) : null,
            'shortcode' => is_scalar($payload['BusinessShortCode'] ?? null) ? mb_substr((string) $payload['BusinessShortCode'], 0, 20) : null,
            'transacted_at' => $this->time($payload['TransTime'] ?? null),
            'data' => array_filter([
                'transaction_type' => is_scalar($payload['TransactionType'] ?? null) ? mb_substr((string) $payload['TransactionType'], 0, 40) : null,
                'trans_time' => is_scalar($payload['TransTime'] ?? null) ? (string) $payload['TransTime'] : null,
            ]),
        ]);

        return self::ACCEPTED;
    }

    private function payoutResult(PaymentMethod $method, array $payload): array
    {
        $result = $payload['Result'] ?? null;
        $this->require(is_array($result) && isset($result['ResultCode']) && (is_string($result['ConversationID'] ?? null) || is_string($result['OriginatorConversationID'] ?? null)));

        $intent = $this->payoutIntent($method, $result['ConversationID'] ?? null, $result['OriginatorConversationID'] ?? null);

        if ($intent === null) {
            return self::ACCEPTED;
        }

        $code = (string) $result['ResultCode'];
        $parameters = $this->parameters($result);
        $data = ['result_code' => $code, 'result_desc' => mb_substr((string) ($result['ResultDesc'] ?? ''), 0, 200)];

        $this->intents->apply($intent, $code === '0'
            ? ProviderResult::succeeded(
                is_string($result['TransactionID'] ?? null) ? strtoupper($result['TransactionID']) : null,
                $data,
                isset($parameters['TransactionAmount']) ? $this->minor((string) $parameters['TransactionAmount'], $intent->currency) : null,
            )
            : ProviderResult::failed($code, __('payments.errors.payout_failed'), $data));

        return self::ACCEPTED;
    }

    private function payoutTimeout(PaymentMethod $method, array $payload): array
    {
        $intent = $this->payoutIntent(
            $method,
            $payload['ConversationID'] ?? ($payload['Result']['ConversationID'] ?? null),
            $payload['OriginatorConversationID'] ?? ($payload['Result']['OriginatorConversationID'] ?? null),
        );

        if ($intent !== null) {
            $this->intents->apply($intent, new ProviderResult('timeout', resultCode: 'queue_timeout', message: __('payments.errors.no_result')));
        }

        return self::ACCEPTED;
    }

    /**
     * A transaction status answer for a manual code: completed with the
     * same amount verifies it; another amount, or a code M-Pesa does not
     * know, is a mismatch; any other failure leaves it unverified for the
     * back office.
     */
    private function statusResult(PaymentMethod $method, array $payload): array
    {
        $result = $payload['Result'] ?? null;
        $this->require(is_array($result) && isset($result['ResultCode']));

        $intent = $this->intentWhere($method, 'verification_ref', $result['ConversationID'] ?? null)
            ?? $this->intentWhere($method, 'verification_ref', $result['OriginatorConversationID'] ?? null);

        if ($intent === null) {
            return self::ACCEPTED;
        }

        $code = (string) $result['ResultCode'];
        $parameters = $this->parameters($result);
        $data = ['check_result_code' => $code, 'check_result_desc' => mb_substr((string) ($result['ResultDesc'] ?? ''), 0, 200)];

        if ($code === '0') {
            $completed = strcasecmp((string) ($parameters['TransactionStatus'] ?? 'Completed'), 'Completed') === 0;
            $amount = isset($parameters['Amount']) ? $this->minor((string) $parameters['Amount'], $intent->currency) : null;
            // The code must have paid this method's shortcode, around the time of the sale.
            $party = (string) ($parameters['CreditPartyName'] ?? '');
            $payTo = DarajaCredentials::of($method)->payTo();
            $when = $this->time($parameters['FinalisedTime'] ?? ($parameters['InitiatedTime'] ?? null));
            $window = (int) config('payments.manual_match_window_hours', 48);
            $completed = $completed
                && ($party === '' || str_starts_with($party, $payTo))
                && ($when === null || abs($when->diffInHours($intent->created_at, true)) <= $window);

            $verdict = $completed
                ? ProviderResult::succeeded($intent->provider_receipt, $data, $amount)
                : ProviderResult::failed('not_found', __('payments.errors.code_not_found'), $data);
        } else {
            $verdict = ProviderResult::failed($code, __('payments.errors.check_failed'), $data);
        }

        $this->intents->applyVerification($intent, $verdict, final: true);

        return self::ACCEPTED;
    }

    private function statusTimeout(PaymentMethod $method, array $payload): array
    {
        $intent = $this->intentWhere($method, 'verification_ref', $payload['ConversationID'] ?? ($payload['Result']['ConversationID'] ?? null));

        if ($intent !== null) {
            $this->intents->applyVerification($intent, ProviderResult::failed('queue_timeout', __('payments.errors.check_failed')), final: true);
        }

        return self::ACCEPTED;
    }

    /**
     * The payout a B2C result names: by Daraja's ConversationID, else by
     * the OriginatorConversationID we sent, which is the intent's own id
     * (also stored as provider_request_id before the call, so a payout
     * whose request timed out is still found).
     */
    private function payoutIntent(PaymentMethod $method, mixed $conversationId, mixed $originatorId): ?PaymentIntent
    {
        $intent = $this->intentWhere($method, 'provider_checkout_id', $conversationId)
            ?? $this->intentWhere($method, 'provider_request_id', $originatorId)
            ?? (is_string($originatorId) && Str::isUuid($originatorId) ? $this->intentWhere($method, 'id', $originatorId) : null);

        return $intent !== null && $intent->mode === 'payout' ? $intent : null;
    }

    private function intentWhere(PaymentMethod $method, string $column, mixed $value): ?PaymentIntent
    {
        if (! is_string($value) || $value === '' || strlen($value) > 100) {
            return null;
        }

        return PaymentIntent::query()->where('payment_method_id', $method->id)->where($column, $value)->first();
    }

    /** @return array<string, scalar|null> ResultParameters as key => value */
    private function parameters(array $result): array
    {
        $list = $result['ResultParameters']['ResultParameter'] ?? [];
        $list = isset($list['Key']) ? [$list] : (array) $list;

        return collect($list)
            ->filter(fn ($p) => is_array($p) && is_string($p['Key'] ?? null))
            ->mapWithKeys(fn (array $p) => [$p['Key'] => is_scalar($p['Value'] ?? null) ? $p['Value'] : null])
            ->all();
    }

    /** "100", "100.00" or 100 in major units → minor units; refused when not a positive amount. */
    private function minor(string $amount, string $currency): int
    {
        try {
            $money = Money::parse(trim($amount), $currency, $this->decimals);
        } catch (InvalidArgumentException) {
            $this->require(false);
        }

        $minor = (int) $money->minor();
        $this->require($minor > 0);

        return $minor;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || preg_match('/^\d{14}$/', (string) $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('YmdHis', (string) $value, 'Africa/Nairobi')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new ApiException(422, 'callback_invalid', __('payments.errors.callback_invalid'));
        }
    }
}
