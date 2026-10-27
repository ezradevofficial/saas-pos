<?php

namespace Modules\Hotel\Folios;

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
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Payment;
use Modules\Hotel\Support\BaseAmounts;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Text;

/**
 * HOT-14, HOT-24: payments, deposits and refunds on a folio, by the
 * company's payment methods.
 *
 * - Cash, card, bank transfer and a typed mobile money receipt post at once.
 * - A mobile money method with a provider can push an M-Pesa STK request
 *   through the core payment intents (reference type `hotel.payment`); the
 *   payment waits `pending` and posts when the provider settles
 *   (PaymentIntentSettled → settle()), exactly once.
 * - A deposit is a payment on the reservation's folio before check-in; it
 *   is applied (HotelPosting `hotel.deposit_applied`) at check-in,
 *   cancellation or no-show.
 * - A refund is limited by the refunder's `max_refund_amount` (RBAC-06, base
 *   currency, an Owner has none) and never more than was paid.
 * Payments are in the folio's currency (a method in another currency is refused).
 */
class FolioPayments
{
    /** Method types that take money into a folio (points, vouchers, credit and room charges are tenders of a sale, not of a folio). */
    private const TYPES = ['cash', 'mobile_money', 'card', 'bank_transfer'];

    public function __construct(
        private readonly Folios $folios,
        private readonly Auditor $auditor,
        private readonly PaymentIntents $intents,
        private readonly PaymentProviderRegistry $providers,
        private readonly LimitRules $limits,
        private readonly ScopeResolver $resolver,
        private readonly CurrencyDecimals $decimals,
        private readonly Postings $postings,
    ) {}

    /**
     * Take money (or a deposit) into a folio.
     *
     * @param  array{kind?: string, payment_method_id: string, amount_minor: int, mode?: string, phone?: ?string, reference?: ?string}  $in
     */
    public function take(Folio $folio, array $in, User $by): Payment
    {
        $kind = $in['kind'] ?? Payment::PAYMENT;
        $method = $this->method($folio, (string) $in['payment_method_id']);
        $amount = (int) $in['amount_minor'];
        $mode = (string) ($in['mode'] ?? 'direct');

        if ($amount < 1) {
            throw HotelError::make(422, 'amount_zero', field: 'amount_minor');
        }

        if ($mode === 'stk' && ! ($method->type === 'mobile_money' && $method->active && $method->provider !== null)) {
            throw HotelError::make(422, 'stk_not_available', field: 'mode');
        }

        $payment = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($folio, $kind, $method, $amount, $mode, $in, $by) {
            $locked = Folio::query()->whereKey($folio->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw HotelError::make(409, 'folio_settled');
            }

            $payment = Payment::create([
                'folio_id' => $locked->id, 'kind' => $kind, 'status' => Payment::PENDING, 'payment_method_id' => $method->id,
                'method_type' => $method->type, 'currency' => $locked->currency, 'amount_minor' => $amount,
                'reference' => isset($in['reference']) ? mb_substr((string) $in['reference'], 0, 120) : null, 'created_by' => $by->id,
            ]);

            if ($mode !== 'stk') {
                $this->settleNow($locked, $payment);
                $this->auditor->record("hotel.folio.{$kind}", $locked, null, ['payment_id' => $payment->id, 'amount_minor' => $amount, 'method' => $method->type]);
            }

            return $payment;
        });

        return $mode === 'stk' ? $this->push($folio, $payment, $method, (string) ($in['phone'] ?? ''), $by) : $payment->refresh();
    }

    /** A refund of $amount to the guest (HOT-24): within the refunder's limit and what the folio holds. */
    public function refund(Folio $folio, array $in, User $by): Payment
    {
        $method = $this->method($folio, (string) $in['payment_method_id']);
        $amount = (int) $in['amount_minor'];

        if ($amount < 1) {
            throw HotelError::make(422, 'amount_zero', field: 'amount_minor');
        }

        $this->assertRefundLimit($folio, $by, $amount);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($folio, $method, $amount, $in, $by) {
            $locked = Folio::query()->whereKey($folio->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw HotelError::make(409, 'folio_settled');
            }

            // Money is paid back only out of money the guest paid in: never more than the credit on the folio.
            if (-$this->folios->balance($locked) < $amount) {
                throw HotelError::make(422, 'refund_exceeds_credit', field: 'amount_minor');
            }

            $payment = Payment::create([
                'folio_id' => $locked->id, 'kind' => Payment::REFUND, 'status' => Payment::PENDING, 'payment_method_id' => $method->id,
                'method_type' => $method->type, 'currency' => $locked->currency, 'amount_minor' => $amount,
                'reference' => isset($in['reference']) ? mb_substr((string) $in['reference'], 0, 120) : null, 'created_by' => $by->id,
            ]);
            $this->settleNow($locked, $payment);
            $this->auditor->record('hotel.folio.refund', $locked, null, ['payment_id' => $payment->id, 'amount_minor' => $amount, 'method' => $method->type]);

            return $payment->refresh();
        });
    }

    /** RBAC-06: $amount (the folio's currency) within the user's `max_refund_amount` (base currency) at the property; an Owner has none. */
    public function assertRefundLimit(Folio $folio, User $by, int $amount, string $error = 'refund_above_limit'): void
    {
        $scope = Scope::branch($folio->branch_id);
        $roles = $this->resolver->roleIds($by, $scope);

        if ($roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists()) {
            return;
        }

        $max = $this->limits->max($by, 'max_refund_amount', $scope);
        $base = app(BaseAmounts::class)->for($folio->company_id, $folio->currency, $amount, now()->toImmutable());
        $baseMinor = $base['base_amount_minor'] ?? $amount;
        $company = Company::query()->findOrFail($folio->company_id);
        $inBase = BigDecimal::of($baseMinor)->withPointMovedLeft($this->decimals->for($company->base_currency));

        if ($max === null || $inBase->isGreaterThan($max)) {
            throw HotelError::make(403, $error);
        }
    }

    /** PaymentIntentSettled: bring a final intent back to its payment, once (HOT-24). */
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
                $payment->forceFill(['status' => Payment::FAILED, 'failure' => (string) ($intent->result_message ?? $intent->result_code ?? $intent->status)])->save();

                return;
            }

            $folio = Folio::query()->whereKey($payment->folio_id)->lockForUpdate()->firstOrFail();
            $payment->forceFill(['reference' => $intent->provider_receipt ?? $payment->reference])->save();
            $this->settleNow($folio, $payment);
            $this->auditor->record("hotel.folio.{$payment->kind}", $folio, null, ['payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor, 'method' => $payment->method_type, 'provider_receipt' => $intent->provider_receipt]);
        });
    }

    /** Ask the provider where a pending push stands (the front desk polls this). */
    public function check(Payment $payment): Payment
    {
        if ($payment->status === Payment::PENDING && $payment->payment_intent_id !== null && ($intent = PaymentIntent::query()->find($payment->payment_intent_id)) !== null) {
            $this->settle($this->intents->check($intent));
        }

        return $payment->refresh();
    }

    /**
     * HOT-14: apply a folio's deposits (once each) when its stay is checked
     * in, cancelled or marked no-show: the liability moves to the guest
     * ledger. Returns the amount applied.
     */
    public function applyDeposits(Folio $folio): int
    {
        $applied = 0;

        foreach (Payment::query()->where('folio_id', $folio->id)->where('kind', Payment::DEPOSIT)->where('status', Payment::SETTLED)->whereNull('converted_at')->lockForUpdate()->get() as $deposit) {
            $line = FolioLine::query()->find($deposit->line_id);

            if ($line === null) {
                continue;
            }

            $deposit->forceFill(['converted_at' => now()])->save();
            $this->postings->depositApplied($folio, $deposit, $line);
            $applied += $deposit->amount_minor;
        }

        return $applied;
    }

    /** The folio line of a settled payment, in the same transaction. */
    private function settleNow(Folio $folio, Payment $payment): void
    {
        $text = match ($payment->kind) {
            Payment::DEPOSIT => 'hotel.lines.deposit',
            Payment::REFUND => 'hotel.lines.refund',
            default => 'hotel.lines.payment',
        };
        $line = $this->folios->post($folio, [
            'kind' => match ($payment->kind) {
                Payment::DEPOSIT => FolioLine::DEPOSIT,
                Payment::REFUND => FolioLine::REFUND,
                default => FolioLine::PAYMENT,
            },
            'description' => Text::line($text, ['method' => $this->methodName($payment->payment_method_id)]),
            // Payments lower what the guest owes (negative); a refund raises it back (positive).
            'amount_minor' => $payment->kind === Payment::REFUND ? $payment->amount_minor : -$payment->amount_minor,
            'net_minor' => $payment->kind === Payment::REFUND ? $payment->amount_minor : -$payment->amount_minor,
            'source' => 'payment',
            'source_id' => $payment->id,
            'payment_method_id' => $payment->payment_method_id,
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'created_by' => $payment->created_by,
        ]);
        $payment->forceFill(['status' => Payment::SETTLED, 'line_id' => $line->id])->save();
    }

    /** Start the M-Pesa push; the payment stays pending until the provider answers. */
    private function push(Folio $folio, Payment $payment, PaymentMethod $method, string $phone, User $by): Payment
    {
        $number = $method->provider === 'mpesa_ke' ? Phones::kenyanMobile($phone) : preg_replace('/[^\d+]/', '', $phone);

        if ($number === null || $number === '') {
            $payment->forceFill(['status' => Payment::FAILED, 'failure' => 'phone_invalid'])->save();

            throw HotelError::make(422, 'phone_invalid', field: 'phone');
        }

        $provider = $this->providers->for($method);
        $locationId = Location::query()->where('branch_id', $folio->branch_id)->whereNull('archived_at')->orderBy('created_at')->value('id');

        try {
            $intent = $this->intents->request($method, $provider, [
                'id' => $payment->id,
                'company_id' => $folio->company_id,
                'location_id' => $locationId,
                'payment_method_id' => $method->id,
                'provider' => (string) ($method->provider ?? $method->type),
                'purpose' => 'sale',
                'mode' => 'stk',
                'currency' => $payment->currency,
                'amount_minor' => $payment->amount_minor,
                'phone' => $number,
                'reference_type' => Payment::REFERENCE,
                'reference' => $folio->id,
                'created_by' => $by->id,
            ]);
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => Payment::FAILED, 'failure' => 'provider_error'])->save();

            throw $e;
        }

        $payment->forceFill(['payment_intent_id' => $intent->id])->save();
        $this->settle($intent);

        return $payment->refresh();
    }

    private function method(Folio $folio, string $methodId): PaymentMethod
    {
        $method = PaymentMethod::query()->find($methodId);

        if ($method === null || $method->company_id !== $folio->company_id || $method->archived_at !== null || ! $method->active) {
            throw HotelError::make(422, 'payment_method_unavailable', field: 'payment_method_id');
        }

        if (! in_array($method->type, self::TYPES, true)) {
            throw HotelError::make(422, 'payment_method_type', field: 'payment_method_id');
        }

        if ($method->currency !== null && $method->currency !== $folio->currency) {
            throw HotelError::make(422, 'payment_currency', ['currency' => $folio->currency], 'payment_method_id');
        }

        return $method;
    }

    private function methodName(string $methodId): string
    {
        return (string) (PaymentMethod::query()->whereKey($methodId)->value('name') ?? '');
    }
}
