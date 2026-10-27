<?php

namespace Modules\Salon\Payments;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\Redemption;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Packages\Packages;

/**
 * SAL-17, SAL-18: what the `salon_credit` payments of a sale use up, and what a void or a refund gives back.
 *
 * The till never has to ask first (it may be offline) and never loses a sale: the payment is taken as the till
 * rang it, and here the server uses up the credit it names, under the row lock of the appointment or the package, once
 * per payment (unique indexes). What cannot be honoured (no deposit left, a package with no session, expired, another
 * customer's, not covering the service) is not applied and is kept as an exception for a person (`salon.exception.view`).
 */
class Credits
{
    public function __construct(
        private readonly Deposits $deposits,
        private readonly Packages $packages,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function apply(string $companyId, array $sale): void
    {
        foreach ($sale['payments'] ?? [] as $payment) {
            if (($payment['method_type'] ?? null) !== 'salon_credit') {
                continue;
            }

            $tender = (array) ($payment['tender'] ?? []);

            match ($tender['source'] ?? '') {
                'deposit' => $this->useDeposit($companyId, $sale, $payment, $tender),
                'package' => $this->usePackage($companyId, $sale, $payment, $tender),
                default => $this->flag($companyId, $sale, 'salon_credit_unknown', $payment['id'], []),
            };
        }
    }

    /** A voided sale gives all of it back. @param array<string, mixed> $sale */
    public function voided(string $companyId, array $sale): void
    {
        foreach (Redemption::query()->where('sale_id', $sale['id'])->where('status', 'redeemed')->get() as $redemption) {
            $this->takeBackSession($redemption);
        }

        foreach (Payment::query()->where('sale_id', $sale['id'])->where('kind', Payment::APPLIED)->where('status', Payment::SETTLED)->get() as $applied) {
            $this->restore($applied->appointment_id, (int) $applied->amount_minor, $applied->currency, $sale['id'], (string) $applied->sale_payment_id, $applied->payment_method_id);
        }
    }

    /** A refunded `salon_credit` payment gives its credit back. @param array<string, mixed> $refund SaleSnapshot::refund() */
    public function refunded(string $companyId, array $refund): void
    {
        foreach ($refund['payments'] ?? [] as $payment) {
            if (($payment['method_type'] ?? null) !== 'salon_credit') {
                continue;
            }

            $tender = (array) ($payment['tender'] ?? []);
            $amount = (int) $payment['amount_in_sale_minor'];

            if (($tender['source'] ?? '') === 'package') {
                $redemption = Redemption::query()->where('sale_id', $refund['sale_id'])->where('package_id', (string) ($tender['package_id'] ?? ''))->where('status', 'redeemed')->first();

                if ($redemption !== null && $amount >= $redemption->value_minor) {
                    $this->takeBackSession($redemption);
                } else {
                    $this->flag($companyId, ['id' => $refund['sale_id'], 'currency' => $refund['currency'], 'branch_id' => $refund['branch_id'] ?? null], 'package_partial_refund', 'refund:'.$payment['id'], ['amount_minor' => $amount]);
                }

                continue;
            }

            $applied = Payment::query()->where('sale_id', $refund['sale_id'])->where('kind', Payment::APPLIED)->first();

            if ($applied !== null) {
                $this->restore($applied->appointment_id, min($amount, (int) $applied->amount_minor), $applied->currency, $refund['sale_id'], (string) $payment['id'], $applied->payment_method_id);
            }
        }
    }

    /** @param array<string, mixed> $sale @param array<string, mixed> $payment @param array<string, mixed> $tender */
    private function useDeposit(string $companyId, array $sale, array $payment, array $tender): void
    {
        $appointmentId = (string) ($tender['appointment_id'] ?? ($sale['extensions']['salon']['appointment_id'] ?? ''));
        $appointment = Str::isUuid($appointmentId) ? Appointment::query()->whereKey($appointmentId)->where('company_id', $companyId)->lockForUpdate()->first() : null;
        $amount = (int) $payment['amount_in_sale_minor'];

        if ($appointment === null) {
            $this->flag($companyId, $sale, 'deposit_unknown', $payment['id'], ['appointment_id' => $appointmentId], $amount);

            return;
        }

        if ($appointment->currency !== $sale['currency']) {
            $this->flag($companyId, $sale, 'deposit_currency', $payment['id'], ['number' => $appointment->number], $amount);

            return;
        }

        // Once per payment: a retried event finds it applied.
        if (Payment::query()->where('kind', Payment::APPLIED)->where('sale_payment_id', $payment['id'])->exists()) {
            return;
        }

        $use = min($amount, max(0, $this->deposits->balance($appointment)));

        if ($use > 0) {
            $made = Payment::query()->insertOrIgnore([[
                'id' => (string) Str::uuid7(), 'tenant_id' => $appointment->tenant_id, 'appointment_id' => $appointment->id, 'kind' => Payment::APPLIED, 'status' => Payment::SETTLED,
                'payment_method_id' => $payment['payment_method_id'] ?? null, 'method_type' => 'salon_credit', 'currency' => $appointment->currency, 'amount_minor' => $use,
                'sale_id' => $sale['id'], 'sale_payment_id' => $payment['id'], 'created_at' => now(), 'updated_at' => now(),
            ]]);

            if ($made > 0) {
                $this->auditor->record('salon.deposit.apply', $appointment, null, ['sale_id' => $sale['id'], 'amount_minor' => $use]);
            }
        }

        if ($use < $amount) {
            $this->flag($companyId, $sale, 'deposit_insufficient', $payment['id'], ['number' => $appointment->number, 'short_minor' => $amount - $use], $amount - $use);
        }
    }

    /** @param array<string, mixed> $sale @param array<string, mixed> $payment @param array<string, mixed> $tender */
    private function usePackage(string $companyId, array $sale, array $payment, array $tender): void
    {
        $key = (string) $payment['id'];

        if (Redemption::query()->where('sale_payment_id', $key)->exists()) {
            return;
        }

        $id = (string) ($tender['package_id'] ?? '');
        $package = Str::isUuid($id) ? Package::query()->whereKey($id)->where('company_id', $companyId)->lockForUpdate()->first() : null;
        $amount = (int) $payment['amount_in_sale_minor'];

        $why = match (true) {
            $package === null => 'package_unknown',
            $package->customer_id !== ($sale['customer_id'] ?? null) => 'package_customer',
            $package->currency !== $sale['currency'] => 'package_currency',
            $package->status === Package::VOID => 'package_void',
            $package->status === Package::EXPIRED || $this->packages->expired($package, CarbonImmutable::parse($sale['sold_at'])) => 'package_expired',
            $package->sessionsLeft() <= 0 => 'package_exhausted',
            ! $this->covers($package, $tender) => 'package_service',
            default => null,
        };

        if ($why !== null) {
            $this->flag($companyId, $sale, $why, $key, ['package_id' => $id], $amount, $package?->id);

            return;
        }

        $value = $package->sessionValue();
        $package->forceFill([
            'sessions_used' => $package->sessions_used + 1, 'remaining_minor' => $package->remaining_minor - $value,
            'status' => $package->sessionsLeft() - 1 <= 0 ? Package::EXHAUSTED : Package::ACTIVE,
        ])->save();
        Redemption::create([
            'package_id' => $package->id, 'appointment_id' => $tender['appointment_id'] ?? ($sale['extensions']['salon']['appointment_id'] ?? null),
            'sale_id' => $sale['id'], 'sale_payment_id' => $key, 'service_id' => $tender['service_id'] ?? null, 'value_minor' => $value,
            'currency' => $package->currency, 'status' => 'redeemed', 'redeemed_at' => now(),
        ]);
        $this->auditor->record('salon.package.redeem', $package, null, ['sale_id' => $sale['id'], 'value_minor' => $value, 'sessions_left' => $package->sessionsLeft()]);

        // The till's figure is its own; what is worth the session is the package's. A difference is for a person.
        if ($amount !== $value) {
            $this->flag($companyId, $sale, 'package_amount_differs', $key, ['till_minor' => $amount, 'session_minor' => $value], abs($amount - $value), $package->id);
        }
    }

    private function covers(Package $package, array $tender): bool
    {
        $services = (array) $package->service_ids;

        return $services === [] || in_array((string) ($tender['service_id'] ?? ''), $services, true);
    }

    private function takeBackSession(Redemption $redemption): void
    {
        $package = Package::query()->whereKey($redemption->package_id)->lockForUpdate()->first();
        $redemption->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();

        if ($package === null || $package->status === Package::VOID) {
            return;
        }

        $package->forceFill([
            'sessions_used' => max(0, $package->sessions_used - 1),
            'remaining_minor' => min($package->price_minor, $package->remaining_minor + $redemption->value_minor),
            'status' => $this->packages->expired($package) ? Package::EXPIRED : Package::ACTIVE,
        ])->save();
        $this->auditor->record('salon.package.restore', $package, null, ['sale_id' => $redemption->sale_id, 'value_minor' => $redemption->value_minor]);
    }

    private function restore(string $appointmentId, int $amount, string $currency, string $saleId, string $paymentId, ?string $methodId): void
    {
        Payment::query()->insertOrIgnore([[
            'id' => (string) Str::uuid7(), 'tenant_id' => app(TenantContext::class)->require(), 'appointment_id' => $appointmentId,
            'kind' => Payment::RESTORED, 'status' => Payment::SETTLED, 'payment_method_id' => $methodId, 'method_type' => 'salon_credit', 'currency' => $currency,
            'amount_minor' => $amount, 'sale_id' => $saleId, 'sale_payment_id' => $paymentId, 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    /** @param array<string, mixed> $sale */
    private function flag(string $companyId, array $sale, string $kind, string $paymentId, array $detail, ?int $amount = null, ?string $packageId = null): void
    {
        SalonException::query()->firstOrCreate(['source_key' => "credit:{$kind}:{$paymentId}"], [
            'company_id' => $companyId, 'branch_id' => $sale['branch_id'] ?? null, 'kind' => $kind, 'sale_id' => $sale['id'] ?? null,
            'package_id' => $packageId, 'amount_minor' => $amount, 'currency' => $sale['currency'] ?? null, 'detail' => $detail,
        ]);
    }
}
