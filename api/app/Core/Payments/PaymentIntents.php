<?php

namespace App\Core\Payments;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Models\PaymentReceipt;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment intents (concept note 7.1): starting them, applying what the
 * provider answers (once: a final status never changes again; each
 * settlement raises PaymentIntentSettled for the owning module), matching
 * money the provider reports as received, refunds paid back through the
 * provider, and the timeout and verification work the scheduler runs.
 *
 * Every write happens in the current tenant's context; provider calls are
 * made outside database transactions, so a slow provider never holds a
 * row lock.
 */
class PaymentIntents
{
    public function __construct(
        private readonly PaymentProviderRegistry $registry,
        private readonly CurrencyDecimals $decimals,
    ) {}

    /**
     * A till asks for money (POST payments/intents). Idempotent by the id
     * the device gives: the same id answers the intent already made.
     *
     * $data: id?, payment_method_id, purpose (sale), mode (stk|manual),
     * amount_minor, currency, phone?, receipt? (manual), reference_type,
     * reference, user_id?
     */
    public function startFromDevice(Device $device, PaymentMethod $method, array $data): PaymentIntent
    {
        if (isset($data['id']) && ($existing = PaymentIntent::query()->find($data['id'])) !== null) {
            return $this->sameRequest($existing, $device, $data);
        }

        $location = $device->location()->with('branch')->firstOrFail();
        $provider = $this->registry->for($method);
        $amount = (int) $data['amount_minor'];
        $this->assertAmount($provider->currencies(), (string) $data['currency'], $amount);

        $phone = null;

        if (filled($data['phone'] ?? null)) {
            $phone = $method->provider === 'mpesa_ke' || $provider->name() === 'mpesa_daraja'
                ? Phones::kenyanMobile((string) $data['phone'])
                : preg_replace('/[^\d+]/', '', (string) $data['phone']);

            if ($phone === null || $phone === '') {
                throw new ApiException(422, 'phone_invalid', __('payments.errors.phone_invalid'), ['phone' => [__('payments.errors.phone_invalid')]]);
            }
        }

        $attributes = [
            'id' => $data['id'] ?? (string) Str::uuid7(),
            'company_id' => $location->branch->company_id,
            'location_id' => $location->id,
            'device_id' => $device->id,
            'payment_method_id' => $method->id,
            'provider' => (string) ($method->provider ?? $method->type),
            'driver' => $provider->name(),
            'purpose' => 'sale',
            'currency' => $data['currency'],
            'amount_minor' => $amount,
            'phone' => $phone,
            'reference_type' => $data['reference_type'],
            'reference' => $data['reference'],
            'created_by' => $data['user_id'] ?? null,
        ];

        if ($data['mode'] === 'stk') {
            if (! $provider->canPush()) {
                throw new ApiException(422, 'push_unsupported', __('payments.errors.push_unsupported'));
            }

            if ($phone === null) {
                throw new ApiException(422, 'phone_invalid', __('payments.errors.phone_invalid'), ['phone' => [__('payments.errors.phone_invalid')]]);
            }

            try {
                return $this->push($method, $attributes);
            } catch (UniqueConstraintViolationException $e) {
                // The same id sent twice at once: answer the row the other request made.
                return $this->sameRequest(self::existingAfter($e, (string) $attributes['id']), $device, $data);
            }
        }

        $intent = $this->recordManual($method, $attributes + ['receipt' => $data['receipt']]);

        return $this->sameRequest($intent, $device, $data);
    }

    /**
     * The intent a repeated device id names, when it is the same request
     * (device, mode, amount, currency, reference, phone or code); 404 for
     * another device's id, 409 for the same id with other content.
     */
    private function sameRequest(PaymentIntent $existing, Device $device, array $data): PaymentIntent
    {
        abort_unless($existing->device_id === $device->id, 404);

        $phone = filled($data['phone'] ?? null) ? (Phones::kenyanMobile((string) $data['phone']) ?? $data['phone']) : null;
        $same = $existing->mode === $data['mode']
            && $existing->amount_minor === (int) $data['amount_minor']
            && $existing->currency === $data['currency']
            && $existing->reference === $data['reference']
            && ($data['mode'] !== 'stk' || $existing->phone === $phone)
            && ($data['mode'] !== 'manual' || $existing->provider_receipt === strtoupper(trim((string) ($data['receipt'] ?? ''))));

        if (! $same) {
            throw new ApiException(409, 'id_conflict', __('payments.errors.id_conflict'));
        }

        return $existing;
    }

    /** The row whose primary key a unique violation reports, else rethrow. */
    private static function existingAfter(UniqueConstraintViolationException $e, string $id): PaymentIntent
    {
        if (! str_contains($e->getMessage(), 'payment_intents_pkey')) {
            throw $e;
        }

        return PaymentIntent::query()->findOrFail($id);
    }

    /**
     * A payment the cashier confirmed with the provider's reference (an
     * M-Pesa code), typed at the till online or uploaded with a sale made
     * offline. Succeeded at once, `unverified` until the provider confirms
     * the code: a C2B confirmation already received matches it now; else a
     * status check runs after `payments.manual_verify_after_minutes`. A
     * code already used for another sale is refused. Idempotent by id.
     *
     * @param  array<string, mixed>  $attributes  PaymentIntent attributes plus `receipt`
     */
    public function recordManual(PaymentMethod $method, array $attributes): PaymentIntent
    {
        $receipt = strtoupper(trim((string) $attributes['receipt']));
        unset($attributes['receipt']);

        if (($existing = PaymentIntent::query()->find($attributes['id'])) !== null) {
            return $this->sameManual($existing, $attributes, $receipt);
        }

        $provider = $this->registry->for($method);

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($method, $attributes, $receipt, $provider) {
                $intent = PaymentIntent::create([
                    ...$attributes,
                    'provider' => (string) ($method->provider ?? $method->type),
                    'driver' => $provider->name(),
                    'mode' => 'manual',
                    'status' => 'succeeded',
                    'verification' => 'unverified',
                    'provider_receipt' => $receipt,
                    'completed_at' => CarbonImmutable::now(),
                    'verify_after' => CarbonImmutable::now()->addMinutes((int) config('payments.manual_verify_after_minutes', 30)),
                ]);

                $received = PaymentReceipt::query()->where('provider', $intent->provider)->where('receipt', $receipt)->lockForUpdate()->first();

                if ($received !== null && $received->status === 'unmatched') {
                    $this->settleReceipt($received, $intent, null);
                }

                return $intent->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'payment_intents_pkey')) {
                return $this->sameManual(PaymentIntent::query()->findOrFail($attributes['id']), $attributes, $receipt);
            }

            throw new ApiException(422, 'receipt_used', __('payments.errors.receipt_used'), ['receipt' => [__('payments.errors.receipt_used')]]);
        }
    }

    /** A repeated manual payment id must name the same sale payment (409 otherwise). */
    private function sameManual(PaymentIntent $existing, array $attributes, string $receipt): PaymentIntent
    {
        $same = $existing->purpose === 'sale' && $existing->mode === 'manual'
            && $existing->provider_receipt === $receipt
            && $existing->amount_minor === (int) $attributes['amount_minor']
            && $existing->reference === (string) $attributes['reference'];

        if (! $same) {
            throw new ApiException(409, 'id_conflict', __('payments.errors.id_conflict'));
        }

        return $existing;
    }

    /**
     * A refund paid back through the provider (M-Pesa B2C) for the sale
     * payment $original (found by its receipt). Pending until the
     * provider's result arrives; `failed` at once when the provider cannot
     * pay out (then the cashier refunds another way). Idempotent by id.
     *
     * @param  array{id: string, amount_minor: int, currency: string, reference_type: string, reference: string, location_id?: ?string, device_id?: ?string, created_by?: ?string}  $data
     */
    public function payout(PaymentMethod $method, PaymentIntent $original, array $data): PaymentIntent
    {
        if (($existing = PaymentIntent::query()->find($data['id'])) !== null) {
            if ($existing->purpose !== 'refund' || $existing->original_intent_id !== $original->id || $existing->amount_minor !== (int) $data['amount_minor']) {
                throw new ApiException(409, 'id_conflict', __('payments.errors.id_conflict'));
            }

            return $existing;
        }

        if ($original->purpose !== 'sale' || $original->status !== 'succeeded') {
            throw new ApiException(422, 'refund_original_unpaid', __('payments.errors.refund_original_unpaid'));
        }

        if ($data['currency'] !== $original->currency) {
            throw new ApiException(422, 'refund_currency_mismatch', __('payments.errors.refund_currency_mismatch'));
        }

        $provider = $this->registry->for($method);
        $this->assertAmount($provider->currencies(), $data['currency'], (int) $data['amount_minor']);

        $intent = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($method, $original, $data, $provider) {
            // One payout at a time per original payment: the cap counts every payout not known to have failed.
            PaymentIntent::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            $paidOut = (int) PaymentIntent::query()->where('original_intent_id', $original->id)->where('purpose', 'refund')
                ->whereNotIn('status', ['failed', 'cancelled'])->sum('amount_minor');

            if ($paidOut + (int) $data['amount_minor'] > $original->amount_minor) {
                throw new ApiException(422, 'refund_exceeds_payment', __('payments.errors.refund_exceeds_payment'));
            }

            return PaymentIntent::create([
                'id' => $data['id'],
                'company_id' => $original->company_id,
                'location_id' => $data['location_id'] ?? $original->location_id,
                'device_id' => $data['device_id'] ?? null,
                'payment_method_id' => $method->id,
                'provider' => (string) ($method->provider ?? $method->type),
                'driver' => $provider->name(),
                'purpose' => 'refund',
                'mode' => 'payout',
                'currency' => $data['currency'],
                'amount_minor' => (int) $data['amount_minor'],
                'phone' => $original->phone,
                'reference_type' => $data['reference_type'],
                'reference' => $data['reference'],
                'original_intent_id' => $original->id,
                'status' => 'pending',
                'expires_at' => CarbonImmutable::now()->addHours((int) config('payments.payout_give_up_hours', 24)),
                'created_by' => $data['created_by'] ?? null,
            ]);
        });

        try {
            $result = $provider->refund($intent, $original, $method);
        } catch (ProviderUnavailable) {
            $result = ProviderResult::failed('unreachable', __('payments.errors.unreachable'));
        }

        return $this->apply($intent, $result);
    }

    /**
     * Apply what the provider answered. A final status never changes: a
     * late or repeated answer is ignored, except that a success without a
     * receipt (an STK query) takes the receipt a later callback brings.
     */
    public function apply(PaymentIntent $intent, ProviderResult $result): PaymentIntent
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($intent, $result) {
            $locked = PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            $recovers = in_array($locked->status, PaymentIntent::RECOVERABLE, true) && $result->status === 'succeeded'
                && ($result->amountMinor === null || $result->amountMinor === $locked->amount_minor);

            if (! $locked->isPending() && ! $recovers) {
                if ($locked->status === 'succeeded' && $locked->provider_receipt === null && $result->receipt !== null) {
                    $locked->fill(['provider_receipt' => $result->receipt])->save();
                }

                return $locked;
            }

            $changes = [
                'provider_request_id' => $result->requestId ?? $locked->provider_request_id,
                'provider_checkout_id' => $result->checkoutId ?? $locked->provider_checkout_id,
                'result_code' => $result->resultCode ?? $locked->result_code,
                'result_message' => $result->message !== null ? mb_substr($result->message, 0, 255) : $locked->result_message,
                'provider_data' => [...(array) $locked->provider_data, ...$result->data],
            ];

            if ($result->isPending()) {
                $locked->fill($changes)->save();

                return $locked;
            }

            // The provider may have the request but did not answer: keep checking.
            if ($result->status === 'unknown') {
                $locked->fill([...$changes, 'status' => 'unknown', 'expires_at' => CarbonImmutable::now()->addSeconds(30)])->save();

                return $locked;
            }

            // The provider reports another amount than asked: never a success.
            if ($result->status === 'succeeded' && $result->amountMinor !== null && $result->amountMinor !== $locked->amount_minor) {
                $result = ProviderResult::failed('amount_mismatch', __('payments.errors.amount_mismatch'), $result->data);
                $changes['result_code'] = 'amount_mismatch';
                $changes['result_message'] = __('payments.errors.amount_mismatch');
            }

            $locked->fill([
                ...$changes,
                'status' => $result->status,
                'provider_receipt' => $result->receipt ?? $locked->provider_receipt,
                'completed_at' => CarbonImmutable::now(),
            ])->save();
            $this->settled($locked);

            return $locked;
        });
    }

    /**
     * Money the provider reports as received (an M-Pesa C2B confirmation),
     * once per receipt: matched to the manual payment that typed its code
     * (verified, or `mismatch` when the amounts differ), else to an open
     * STK push of the same method, amount and account reference made
     * within `payments.c2b_match_window_minutes`, else left unmatched for
     * the back office.
     *
     * @param  array{receipt: string, amount_minor: int, currency: string, account_reference: ?string, shortcode: ?string, transacted_at: ?CarbonImmutable, data: array<string, scalar|null>}  $received
     */
    public function receive(PaymentMethod $method, array $received, bool $match = true, ?string $flag = null): PaymentReceipt
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($method, $received, $match, $flag) {
            $provider = (string) ($method->provider ?? $method->type);
            $receipt = strtoupper($received['receipt']);
            $existing = PaymentReceipt::query()->where('provider', $provider)->where('receipt', $receipt)->first();

            if ($existing !== null) {
                return $existing;
            }

            $row = PaymentReceipt::create([
                'company_id' => $method->company_id,
                'payment_method_id' => $method->id,
                'provider' => $provider,
                'receipt' => $receipt,
                'currency' => $received['currency'],
                'amount_minor' => $received['amount_minor'],
                'account_reference' => $received['account_reference'],
                'shortcode' => $received['shortcode'],
                'transacted_at' => $received['transacted_at'],
                'status' => 'unmatched',
                'flag' => $flag,
                'provider_data' => $received['data'],
            ]);

            // Already paid through an intent with this receipt (an STK push on a Paybill that
            // also confirms by C2B): linked, nothing else changes.
            $paid = PaymentIntent::query()->where('payment_method_id', $method->id)->where('provider_receipt', $receipt)
                ->where('purpose', 'sale')->where('status', 'succeeded')->where('mode', 'stk')->first();

            if ($paid !== null) {
                $row->fill(['status' => 'matched', 'flag' => null, 'payment_intent_id' => $paid->id, 'matched_at' => CarbonImmutable::now()])->save();

                return $row->refresh();
            }

            $intent = $match ? $this->candidateFor($row) : null;

            if ($intent !== null) {
                $this->settleReceipt($row, $intent, null);
            }

            return $row->refresh();
        });
    }

    /**
     * The back office matches an unmatched receipt to an intent of the
     * same company and method (`core.payment.match`): a manual payment
     * (verified against the receipt) or an STK push that never got its
     * answer (succeeded with the receipt).
     */
    public function match(PaymentReceipt $receipt, PaymentIntent $intent, User $user): PaymentReceipt
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($receipt, $intent, $user) {
            $receipt = PaymentReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $intent = PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            $matchable = $receipt->status === 'unmatched'
                && $intent->purpose === 'sale'
                && $intent->payment_method_id === $receipt->payment_method_id
                && $intent->currency === $receipt->currency
                && ($intent->verification === 'unverified' || ($intent->mode === 'stk' && in_array($intent->status, ['pending', 'unknown', 'timeout'], true)));

            if (! $matchable) {
                throw new ApiException(422, 'not_matchable', __('payments.errors.not_matchable'));
            }

            if ($intent->mode === 'stk' && $intent->amount_minor !== $receipt->amount_minor) {
                throw new ApiException(422, 'amount_mismatch', __('payments.errors.amount_mismatch'));
            }

            $this->settleReceipt($receipt, $intent, $user);

            return $receipt->refresh();
        });
    }

    /**
     * Scheduled work in the current tenant (payments:process-timers):
     * STK pushes past their timeout are checked with the provider, then
     * timed out after `payments.stk_give_up_seconds`; payouts without a
     * result after `payments.payout_give_up_hours` time out; manual codes
     * due for a check are sent to the provider (the answer comes by
     * callback). Returns how many intents were looked at.
     */
    public function processTimers(CarbonImmutable $at): int
    {
        $count = 0;
        // A new provider call starts only when a whole call still fits in the run.
        $stop = microtime(true) + (int) config('payments.run_seconds', 55) - ((int) config('payments.mpesa.timeout', 15) + 5);

        $due = PaymentIntent::query()->whereIn('status', ['pending', 'unknown'])->where('expires_at', '<=', $at)->orderBy('expires_at')->limit(100)->get();

        foreach ($due as $intent) {
            // Stay inside the worker's time limit; the scheduler does the rest next minute.
            if (microtime(true) >= $stop) {
                return $count;
            }

            $count++;
            $this->expire($intent, $at);
        }

        $unverified = PaymentIntent::query()->where('verification', 'unverified')->whereNull('verification_ref')
            ->where('verify_after', '<=', $at)->orderBy('verify_after')->limit(100)->get();

        foreach ($unverified as $intent) {
            if (microtime(true) >= $stop) {
                break;
            }

            $count++;
            $this->verify($intent);
        }

        return $count;
    }

    /** A manual payment checked with the provider (the answer usually comes later by callback). */
    public function verify(PaymentIntent $intent): PaymentIntent
    {
        $method = PaymentMethod::query()->findOrFail($intent->payment_method_id);

        try {
            $result = $this->registry->for($method)->reconcile($intent, $method);
        } catch (ProviderUnavailable) {
            // Try again at the next run, a little later.
            $intent->fill(['verify_after' => CarbonImmutable::now()->addMinutes(10)])->save();

            return $intent;
        }

        return $this->applyVerification($intent, $result);
    }

    /**
     * What the provider says about a manual code: pending (a reference to
     * wait for), succeeded with its amount (verified or mismatch), or
     * failed (no automatic check: left unverified with the reason, for
     * the back office).
     */
    public function applyVerification(PaymentIntent $intent, ProviderResult $result, bool $final = false): PaymentIntent
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($intent, $result, $final) {
            $locked = PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($locked->verification !== 'unverified') {
                return $locked;
            }

            if ($result->isPending() && ! $final) {
                $locked->fill(['verification_ref' => $result->checkoutId ?? $result->requestId])->save();

                return $locked;
            }

            if ($result->status === 'succeeded') {
                $matches = $result->amountMinor === null || $result->amountMinor === $locked->amount_minor;
                $locked->fill([
                    'verification' => $matches ? 'verified' : 'mismatch',
                    'result_code' => $matches ? $locked->result_code : 'amount_mismatch',
                    'result_message' => $matches ? $locked->result_message : __('payments.errors.amount_mismatch'),
                    'provider_data' => [...(array) $locked->provider_data, ...$result->data],
                ])->save();
                $this->settled($locked);

                return $locked;
            }

            $locked->fill([
                'verification' => $result->status === 'failed' && $result->resultCode === 'not_found' ? 'mismatch' : 'unverified',
                // No automatic check again: the back office decides.
                'verify_after' => null,
                'result_code' => $result->resultCode,
                'result_message' => $result->message !== null ? mb_substr($result->message, 0, 255) : null,
                'provider_data' => [...(array) $locked->provider_data, ...$result->data],
            ])->save();

            if ($locked->verification === 'mismatch') {
                $this->settled($locked);
            }

            return $locked;
        });
    }

    private function expire(PaymentIntent $intent, CarbonImmutable $at): void
    {
        if ($intent->mode === 'payout') {
            $this->apply($intent, new ProviderResult('timeout', resultCode: 'no_result', message: __('payments.errors.no_result')));

            return;
        }

        $method = PaymentMethod::query()->findOrFail($intent->payment_method_id);

        try {
            $result = $this->registry->for($method)->status($intent, $method);
        } catch (ProviderUnavailable) {
            $result = ProviderResult::pending();
        }

        if ($result->isPending()) {
            if ($intent->created_at->lessThanOrEqualTo($at->subSeconds((int) config('payments.stk_give_up_seconds', 300)))) {
                $this->apply($intent, new ProviderResult('timeout', resultCode: 'no_answer', message: __('payments.errors.not_reached')));
            } else {
                $intent->fill(['expires_at' => $at->addSeconds(30)])->save();
            }

            return;
        }

        $this->apply($intent, $result);
    }

    /** STK push: the intent is stored first (pending), then the provider is asked. */
    private function push(PaymentMethod $method, array $attributes): PaymentIntent
    {
        $intent = PaymentIntent::create([
            ...$attributes,
            'mode' => 'stk',
            'status' => 'pending',
            'account_reference' => strtoupper(substr(str_replace('-', '', (string) $attributes['id']), -12)),
            'expires_at' => CarbonImmutable::now()->addSeconds((int) config('payments.stk_timeout_seconds', 90)),
        ]);

        try {
            $result = $this->registry->for($method)->initiate($intent, $method);
        } catch (ProviderUnavailable) {
            // Nothing is known: the push may have reached the phone. Keep checking (and match
            // a late result or a C2B confirmation) instead of failing it.
            $result = new ProviderResult('unknown', resultCode: 'no_answer_from_provider', message: __('payments.errors.provider_no_answer'));
        }

        return $this->apply($intent, $result);
    }

    private function candidateFor(PaymentReceipt $receipt): ?PaymentIntent
    {
        $window = (int) config('payments.manual_match_window_hours', 48);
        $manual = PaymentIntent::query()->where('payment_method_id', $receipt->payment_method_id)->where('provider_receipt', $receipt->receipt)
            ->where('purpose', 'sale')->where('verification', 'unverified')
            ->when($receipt->transacted_at !== null, fn ($q) => $q
                ->where('created_at', '>=', $receipt->transacted_at->subHours($window))
                ->where('created_at', '<=', $receipt->transacted_at->addHours($window)))
            ->lockForUpdate()->first();

        if ($manual !== null) {
            return $manual;
        }

        if (blank($receipt->account_reference)) {
            return null;
        }

        return PaymentIntent::query()
            ->where('payment_method_id', $receipt->payment_method_id)
            ->where('purpose', 'sale')->where('mode', 'stk')
            ->whereIn('status', ['pending', 'unknown', 'timeout'])
            ->where('amount_minor', $receipt->amount_minor)
            ->where('currency', $receipt->currency)
            ->where('account_reference', strtoupper((string) $receipt->account_reference))
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes((int) config('payments.c2b_match_window_minutes', 30)))
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();
    }

    /** Link $receipt and $intent (both locked by the caller's transaction). */
    private function settleReceipt(PaymentReceipt $receipt, PaymentIntent $intent, ?User $user): void
    {
        if ($intent->verification === 'unverified') {
            $matches = $intent->amount_minor === $receipt->amount_minor && $intent->currency === $receipt->currency;
            $intent->fill([
                'verification' => $matches ? 'verified' : 'mismatch',
                'result_code' => $matches ? $intent->result_code : 'amount_mismatch',
                'result_message' => $matches ? $intent->result_message : __('payments.errors.amount_mismatch'),
            ])->save();
        } else {
            $intent->fill([
                'status' => 'succeeded',
                'provider_receipt' => $receipt->receipt,
                'result_code' => 'c2b',
                'completed_at' => CarbonImmutable::now(),
            ])->save();
        }

        $this->settled($intent);

        $receipt->fill([
            'status' => 'matched',
            'payment_intent_id' => $intent->id,
            'matched_by' => $user?->id,
            'matched_at' => CarbonImmutable::now(),
        ])->save();
    }

    /** Tell the module that owns the reference (after commit, ids only). */
    private function settled(PaymentIntent $intent): void
    {
        PaymentIntentSettled::dispatch((string) $intent->tenant_id, (string) $intent->id, (string) $intent->reference_type, (string) $intent->reference);
    }

    /** @param array<string, bool> $currencies */
    private function assertAmount(array $currencies, string $currency, int $amount): void
    {
        if ($currencies !== [] && ! array_key_exists($currency, $currencies)) {
            throw new ApiException(422, 'currency_not_supported', __('payments.errors.currency_not_supported', ['currencies' => implode(', ', array_keys($currencies))]));
        }

        if (($currencies[$currency] ?? false) && $amount % (10 ** $this->decimals->for($currency)) !== 0) {
            throw new ApiException(422, 'whole_units', __('payments.errors.whole_units', ['currency' => $currency]));
        }
    }
}
