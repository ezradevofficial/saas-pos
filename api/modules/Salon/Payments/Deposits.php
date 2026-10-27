<?php

namespace Modules\Salon\Payments;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Identity\Models\User;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\PaymentProviderRegistry;
use App\Core\Payments\Phones;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Payment;
use Modules\Salon\Support\Errors;

/**
 * SAL-11, SAL-17: deposits on an appointment, taken with the company's payment methods, and paid back.
 *
 * - Cash, card, bank transfer and a typed mobile money receipt are settled at once.
 * - A mobile money method with a provider can push an M-Pesa request through the core payment intents (reference type
 *   `salon.payment`); the payment waits `pending` and is settled exactly once when the provider answers
 *   (PaymentIntentSettled → settle()).
 * - A deposit is used at the till (a `salon_credit` payment, Credits), kept against a fee, or refunded: a refund is
 *   limited by the refunder's `max_refund_amount` (RBAC-06, an Owner has none) and never more than the balance.
 * - The balance is settled deposits and restored credit, less refunds and credit applied; every change of it happens
 *   under the appointment's row lock.
 */
class Deposits
{
    /** Method types that take money for a deposit (credit, points, vouchers and the like are tenders of a sale). */
    private const TYPES = ['cash', 'mobile_money', 'card', 'bank_transfer'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly PaymentIntents $intents,
        private readonly PaymentProviderRegistry $providers,
        private readonly LimitRules $limits,
        private readonly ScopeResolver $resolver,
        private readonly CurrencyDecimals $decimals,
    ) {}

    /** What the appointment holds for the client now, in minor units of its currency. */
    public function balance(Appointment|string $appointment): int
    {
        $id = $appointment instanceof Appointment ? $appointment->id : $appointment;
        $sum = fn (array $kinds) => (int) Payment::query()->where('appointment_id', $id)->where('status', Payment::SETTLED)->whereIn('kind', $kinds)->sum('amount_minor');

        return $sum([Payment::DEPOSIT, Payment::RESTORED]) - $sum([Payment::REFUND, Payment::APPLIED]);
    }

    /** @param array{payment_method_id: string, amount_minor: int|string, mode?: string, phone?: ?string, reference?: ?string} $in */
    public function take(Appointment $appointment, array $in, ?User $by): Payment
    {
        $method = $this->method($appointment, (string) $in['payment_method_id']);
        $amount = (int) $in['amount_minor'];
        $mode = (string) ($in['mode'] ?? 'direct');

        if ($amount < 1) {
            throw Errors::make(422, 'amount_zero', field: 'amount_minor');
        }

        if ($mode === 'stk' && ! ($method->type === 'mobile_money' && $method->active && $method->provider !== null)) {
            throw Errors::make(422, 'stk_not_available', field: 'mode');
        }

        $payment = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($appointment, $method, $amount, $mode, $in, $by) {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, Appointment::OPEN, true)) {
                throw Errors::make(409, 'appointment_not_open');
            }

            // A deposit is part of the bill, never more than it (pending pushes count: they may succeed).
            $pending = (int) Payment::query()->where('appointment_id', $locked->id)->where('kind', Payment::DEPOSIT)->where('status', Payment::PENDING)->sum('amount_minor');

            if ($this->balance($locked) + $pending + $amount > $locked->total_minor) {
                throw Errors::make(422, 'deposit_exceeds_total', field: 'amount_minor');
            }

            $payment = Payment::create([
                'appointment_id' => $locked->id, 'kind' => Payment::DEPOSIT, 'status' => $mode === 'stk' ? Payment::PENDING : Payment::SETTLED,
                'payment_method_id' => $method->id, 'method_type' => $method->type, 'currency' => $locked->currency, 'amount_minor' => $amount,
                'reference' => isset($in['reference']) ? mb_substr((string) $in['reference'], 0, 120) : null, 'created_by' => $by?->id,
            ]);

            if ($mode !== 'stk') {
                $this->settled($locked, $payment);
            }

            return $payment;
        });

        return $mode === 'stk' ? $this->push($appointment, $payment, $method, (string) ($in['phone'] ?? ''), $by) : $payment->refresh();
    }

    /** SAL-11: pays a deposit back, within the refunder's limit and what the appointment holds. */
    public function refund(Appointment $appointment, array $in, User $by): Payment
    {
        $method = $this->method($appointment, (string) $in['payment_method_id']);
        $amount = (int) $in['amount_minor'];

        if ($amount < 1) {
            throw Errors::make(422, 'amount_zero', field: 'amount_minor');
        }

        $this->assertRefundLimit($appointment, $by, $amount);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($appointment, $method, $amount, $in, $by) {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            // A fee still due is kept out of the refund until it is paid or waived.
            $kept = $locked->fee_status === 'due' ? (int) $locked->fee_minor : 0;

            if ($this->balance($locked) - $kept < $amount) {
                throw Errors::make(422, 'refund_exceeds_credit', field: 'amount_minor');
            }

            $payment = Payment::create([
                'appointment_id' => $locked->id, 'kind' => Payment::REFUND, 'status' => Payment::SETTLED, 'payment_method_id' => $method->id,
                'method_type' => $method->type, 'currency' => $locked->currency, 'amount_minor' => $amount,
                'reference' => isset($in['reference']) ? mb_substr((string) $in['reference'], 0, 120) : null, 'created_by' => $by->id,
            ]);
            $this->auditor->record('salon.deposit.refund', $locked, null, ['payment_id' => $payment->id, 'amount_minor' => $amount, 'method' => $method->type]);

            return $payment->refresh();
        });
    }

    /** RBAC-06: $amount (the base currency) within the user's `max_refund_amount` at the branch; an Owner has none. */
    public function assertRefundLimit(Appointment $appointment, User $by, int $amount): void
    {
        $scope = $appointment->scope();
        $roles = $this->resolver->roleIds($by, $scope);

        if ($roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists()) {
            return;
        }

        $max = $this->limits->max($by, 'max_refund_amount', $scope);
        $company = Company::query()->findOrFail($appointment->company_id);
        $inBase = BigDecimal::of($amount)->withPointMovedLeft($this->decimals->for($company->base_currency));

        if ($max === null || $inBase->isGreaterThan($max)) {
            throw Errors::make(403, 'refund_above_limit');
        }
    }

    /** PaymentIntentSettled: brings a final intent back to its payment, once. */
    public function settle(PaymentIntent $intent): void
    {
        if (in_array($intent->status, ['pending', 'unknown'], true) || $intent->reference_type !== Payment::REFERENCE) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($intent) {
            $payment = Payment::query()->whereKey($intent->id)->lockForUpdate()->first();

            if ($payment === null || $payment->status !== Payment::PENDING) {
                return;
            }

            if ($intent->status !== 'succeeded') {
                $payment->forceFill(['status' => Payment::FAILED, 'failure' => mb_substr((string) ($intent->result_message ?? $intent->result_code ?? $intent->status), 0, 60)])->save();

                return;
            }

            $appointment = Appointment::query()->whereKey($payment->appointment_id)->lockForUpdate()->firstOrFail();
            $payment->forceFill(['reference' => $intent->provider_receipt ?? $payment->reference])->save();
            $this->settled($appointment, $payment);
        });
    }

    /** Asks the provider where a pending push stands (the front desk polls this). */
    public function check(Payment $payment): Payment
    {
        if ($payment->status === Payment::PENDING && $payment->payment_intent_id !== null && ($intent = PaymentIntent::query()->find($payment->payment_intent_id)) !== null) {
            $this->settle($this->intents->check($intent));
        }

        return $payment->refresh();
    }

    /** The payment is money held now: mark it, release the online hold when the deposit is met, and audit. */
    private function settled(Appointment $appointment, Payment $payment): void
    {
        $payment->forceFill(['status' => Payment::SETTLED])->save();

        if ($appointment->deposit_hold_until !== null && $this->balance($appointment) >= $appointment->deposit_required_minor) {
            $appointment->forceFill(['deposit_hold_until' => null])->save();
        }

        $this->auditor->record('salon.deposit.take', $appointment, null, ['payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor, 'method' => $payment->method_type, 'reference' => $payment->reference]);
    }

    /** Starts the M-Pesa push; the payment stays pending until the provider answers. */
    private function push(Appointment $appointment, Payment $payment, PaymentMethod $method, string $phone, ?User $by): Payment
    {
        $number = $method->provider === 'mpesa_ke' ? Phones::kenyanMobile($phone) : preg_replace('/[^\d+]/', '', $phone);

        if ($number === null || $number === '') {
            $payment->forceFill(['status' => Payment::FAILED, 'failure' => 'phone_invalid'])->save();

            throw Errors::make(422, 'phone_invalid', field: 'phone');
        }

        $provider = $this->providers->for($method);
        $locationId = $appointment->location_id ?? Location::query()->where('branch_id', $appointment->branch_id)->whereNull('archived_at')->orderBy('created_at')->value('id');

        try {
            $intent = $this->intents->request($method, $provider, [
                'id' => $payment->id,
                'company_id' => $appointment->company_id,
                'location_id' => $locationId,
                'payment_method_id' => $method->id,
                'provider' => (string) ($method->provider ?? $method->type),
                'purpose' => 'sale',
                'mode' => 'stk',
                'currency' => $payment->currency,
                'amount_minor' => $payment->amount_minor,
                'phone' => $number,
                'reference_type' => Payment::REFERENCE,
                'reference' => $appointment->id,
                'created_by' => $by?->id,
            ]);
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => Payment::FAILED, 'failure' => 'provider_error'])->save();

            throw $e;
        }

        $payment->forceFill(['payment_intent_id' => $intent->id])->save();
        $this->settle($intent);

        return $payment->refresh();
    }

    private function method(Appointment $appointment, string $methodId): PaymentMethod
    {
        $method = PaymentMethod::query()->find($methodId);

        if ($method === null || $method->company_id !== $appointment->company_id || $method->archived_at !== null || ! $method->active) {
            throw Errors::make(422, 'payment_method_unavailable', field: 'payment_method_id');
        }

        if (! in_array($method->type, self::TYPES, true)) {
            throw Errors::make(422, 'payment_method_type', field: 'payment_method_id');
        }

        if ($method->currency !== null && $method->currency !== $appointment->currency) {
            throw Errors::make(422, 'payment_currency', ['currency' => $appointment->currency], 'payment_method_id');
        }

        return $method;
    }
}
