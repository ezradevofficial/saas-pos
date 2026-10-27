<?php

namespace Modules\Salon\Sales;

use App\Core\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Salon\Appointments\Lifecycle;
use Modules\Salon\Commissions\Commissions;
use Modules\Salon\Commissions\Tips;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Staff;
use Modules\Salon\Packages\Packages;
use Modules\Salon\Payments\Credits;
use Modules\Salon\Support\Settings;

/**
 * SAL-16 to SAL-18, SAL-21, SAL-22: what a POS sale, its void and its refund mean for the salon. Read from the events'
 * snapshots (no POS table is read). Every write is once per source (unique indexes and keys), so a retried listener
 * adds nothing. A sale is never judged out: what does not match is kept as an exception for a person.
 *
 * - The appointment named in `extensions.salon.appointment_id` becomes `paid` and linked to the sale (its lines to the
 *   sale's lines, by item and order); a fee sale (`fee: true`) settles the fee instead.
 * - The sale's `salon_credit` payments use up deposits and package sessions; package items sold make packages.
 * - Service lines earn their staff member from the appointment (the server's record, never the device's word); product
 *   lines earn the seller the till names (`sellers`), else the appointment's first staff member. The tip line is shared.
 */
class SaleLinks
{
    public function __construct(
        private readonly Credits $credits,
        private readonly Packages $packages,
        private readonly Commissions $commissions,
        private readonly Tips $tips,
        private readonly Lifecycle $lifecycle,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function completed(string $saleId, string $companyId, array $sale): void
    {
        $this->packages->sell($companyId, $sale);
        $this->credits->apply($companyId, $sale);

        $ext = is_array($sale['extensions']['salon'] ?? null) ? $sale['extensions']['salon'] : null;

        if ($ext === null) {
            return;
        }

        $settings = Settings::for($companyId);
        $appointment = $this->appointment($companyId, $sale, $ext);
        $lines = collect($sale['lines'] ?? []);
        $tipLines = $settings['tip_item_id'] === null ? collect() : $lines->where('item_id', $settings['tip_item_id']);
        $feeLines = $settings['fee_item_id'] === null ? collect() : $lines->where('item_id', $settings['fee_item_id']);
        $packageItems = PackageType::query()->where('company_id', $companyId)->whereNull('archived_at')->pluck('item_id')->all();
        $plain = $lines->reject(fn (array $l) => $tipLines->contains('id', $l['id']) || $feeLines->contains('id', $l['id']) || in_array($l['item_id'], $packageItems, true))->values();

        $attributions = [];
        $first = null;

        if ($appointment !== null && ! empty($ext['fee'])) {
            $this->settleFee($appointment, $sale);
        } elseif ($appointment !== null) {
            [$matched, $first] = $this->pay($appointment, $sale, $plain);
            $plain = $plain->reject(fn (array $l) => isset($matched[$l['id']]))->values();

            foreach ($matched as $saleLineId => $line) {
                $attributions[] = [
                    'line' => $lines->firstWhere('id', $saleLineId), 'staff_id' => $line->staff_id, 'kind' => 'service', 'service_id' => $line->service_id,
                    'appointment_id' => $appointment->id, 'appointment_line_id' => $line->id,
                ];
            }
        }

        // Products: the seller the till names (an active staff member of the company), else the appointment's first.
        $sellers = $this->sellers($companyId, (array) ($ext['sellers'] ?? []));

        foreach ($plain as $line) {
            $staffId = $sellers[$line['id']] ?? $first;

            if ($staffId !== null) {
                $attributions[] = ['line' => $line, 'staff_id' => $staffId, 'kind' => 'product', 'service_id' => null, 'appointment_id' => $appointment?->id, 'appointment_line_id' => null];
            }
        }

        $this->commissions->earn($companyId, $sale, $attributions);
        $this->tips->take($companyId, $sale, (int) $tipLines->sum(fn (array $l) => (int) $l['total_minor']), $this->namedTips($ext), $first);
    }

    /** @param array<string, mixed> $sale */
    public function voided(string $saleId, string $companyId, array $sale, string $voidId, ?string $voidedAt): void
    {
        $this->credits->voided($companyId, $sale);
        $this->commissions->voided($companyId, $sale, $voidId, $voidedAt);
        $this->packages->void($companyId, $sale);

        $ext = is_array($sale['extensions']['salon'] ?? null) ? $sale['extensions']['salon'] : [];
        $linked = Appointment::query()->where('company_id', $companyId)->where('sale_id', $saleId)->lockForUpdate()->first();

        if ($linked !== null && $linked->status === Appointment::PAID) {
            $linked->forceFill(['status' => Appointment::COMPLETED, 'sale_id' => null, 'paid_at' => null])->save();
            Line::query()->where('appointment_id', $linked->id)->update(['sale_line_id' => null, 'net_minor' => null]);
            $this->auditor->record('salon.appointment.unpay', $linked, null, ['sale_id' => $saleId, 'reason' => 'void']);
        }

        // A fee sale voided makes the fee due again.
        $appointmentId = (string) ($ext['appointment_id'] ?? '');

        if (! empty($ext['fee']) && Str::isUuid($appointmentId)) {
            $fee = Appointment::query()->where('company_id', $companyId)->whereKey($appointmentId)->lockForUpdate()->first();

            if ($fee !== null && $fee->fee_status === 'paid') {
                $fee->forceFill(['fee_status' => 'due'])->save();
            }
        }
    }

    /** @param array<string, mixed> $refund SaleSnapshot::refund() */
    public function refunded(string $companyId, array $refund): void
    {
        $this->credits->refunded($companyId, $refund);
        $this->commissions->refunded($companyId, $refund);
    }

    /**
     * Links the appointment to the sale and pays it. Returns the sale lines matched to appointment lines (by sale line id)
     * and the appointment's first staff member.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{0: array<string, Line>, 1: ?string}
     */
    private function pay(Appointment $appointment, array $sale, $candidates): array
    {
        $lines = Line::query()->where('appointment_id', $appointment->id)->orderBy('position')->orderBy('id')->get();
        $first = $lines->first()?->staff_id;

        if (in_array($appointment->status, [Appointment::CANCELLED, Appointment::NO_SHOW], true)) {
            $this->flag($appointment, $sale, 'appointment_cancelled', []);

            return [[], $first];
        }

        if ($appointment->sale_id !== null && $appointment->sale_id !== $sale['id']) {
            $this->flag($appointment, $sale, 'appointment_already_paid', ['other_sale_id' => $appointment->sale_id]);

            return [[], $first];
        }

        $matched = [];
        $free = $candidates->all();
        $missing = [];

        foreach ($lines as $line) {
            $index = collect($free)->search(fn (array $l) => $l['item_id'] === $line->item_id);

            if ($index === false) {
                $missing[] = $line->service_id;

                continue;
            }

            $matched[$free[$index]['id']] = $line;
            unset($free[$index]);
        }

        if ($missing !== []) {
            $this->flag($appointment, $sale, 'sale_mismatch', ['missing_services' => $missing]);
        }

        // The service is given: it is finished (stock leaves) before it is paid.
        if (in_array($appointment->status, [Appointment::BOOKED, Appointment::CHECKED_IN, Appointment::IN_SERVICE], true)) {
            $appointment = $this->lifecycle->finish($appointment, null, CarbonImmutable::parse($sale['sold_at']));
        }

        foreach ($matched as $saleLineId => $line) {
            $line->forceFill(['sale_line_id' => $saleLineId, 'net_minor' => (int) ($candidates->firstWhere('id', $saleLineId)['net_minor'] ?? 0)])->save();
        }

        $appointment->forceFill(['status' => Appointment::PAID, 'sale_id' => $sale['id'], 'paid_at' => CarbonImmutable::parse($sale['sold_at'])])->save();
        $this->auditor->record('salon.appointment.pay', $appointment, null, ['sale_id' => $sale['id']]);

        return [$matched, $first];
    }

    private function settleFee(Appointment $appointment, array $sale): void
    {
        if ($appointment->fee_status !== 'due') {
            $this->flag($appointment, $sale, 'fee_not_due', ['fee_status' => $appointment->fee_status]);

            return;
        }

        $appointment->forceFill(['fee_status' => 'paid'])->save();
        $this->auditor->record('salon.fee.pay', $appointment, null, ['sale_id' => $sale['id'], 'fee_minor' => $appointment->fee_minor]);
    }

    private function appointment(string $companyId, array $sale, array $ext): ?Appointment
    {
        $id = (string) ($ext['appointment_id'] ?? '');

        if ($id === '') {
            return null;
        }

        $appointment = Str::isUuid($id) ? Appointment::query()->where('company_id', $companyId)->whereKey($id)->lockForUpdate()->first() : null;

        if ($appointment === null) {
            SalonException::query()->firstOrCreate(['source_key' => "appointment-unknown:{$sale['id']}"], [
                'company_id' => $companyId, 'branch_id' => $sale['branch_id'] ?? null, 'kind' => 'appointment_unknown', 'sale_id' => $sale['id'], 'currency' => $sale['currency'] ?? null,
                'detail' => ['appointment_id' => $id],
            ]);
        }

        return $appointment;
    }

    /** @return array<string, string> sale line id => active staff id of the company */
    private function sellers(string $companyId, array $named): array
    {
        $ids = array_filter(array_column(array_filter($named, 'is_array'), 'staff_id'), fn ($id) => is_string($id) && Str::isUuid($id));
        $valid = $ids === [] ? [] : Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($ids)->pluck('id')->all();
        $out = [];

        foreach ($named as $row) {
            if (is_array($row) && is_string($row['line_id'] ?? null) && in_array($row['staff_id'] ?? null, $valid, true)) {
                $out[$row['line_id']] = $row['staff_id'];
            }
        }

        return $out;
    }

    /** @return list<array{staff_id: string, amount_minor: int|string}> */
    private function namedTips(array $ext): array
    {
        return array_values(array_filter((array) ($ext['tips'] ?? []), fn ($t) => is_array($t) && is_string($t['staff_id'] ?? null) && Str::isUuid($t['staff_id']) && preg_match('/^\d{1,15}$/', (string) ($t['amount_minor'] ?? '')) === 1));
    }

    private function flag(Appointment $appointment, array $sale, string $kind, array $detail): void
    {
        SalonException::query()->firstOrCreate(['source_key' => "{$kind}:{$sale['id']}"], [
            'company_id' => $appointment->company_id, 'branch_id' => $appointment->branch_id, 'kind' => $kind, 'appointment_id' => $appointment->id, 'sale_id' => $sale['id'],
            'currency' => $sale['currency'] ?? null, 'detail' => [...$detail, 'number' => $appointment->number],
        ]);
    }
}
