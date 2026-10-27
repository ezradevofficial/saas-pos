<?php

namespace Modules\Salon\Appointments;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Reminders\Notices;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Settings;

/**
 * SAL-10, SAL-13: the day of an appointment: check-in, start, finish, cancellation, no-show and the fee a late
 * cancellation or a no-show makes.
 *
 * Every step is forward-only and idempotent: asked again, or after a later step (a till's records arrive late and in
 * any order), it answers with the appointment as it stands and changes nothing. Each runs under the appointment's row
 * lock, so two desks cannot take it two ways at once.
 */
class Lifecycle
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Notices $notices,
        private readonly Consumption $consumption,
    ) {}

    /** The time $at names, held between the appointment's creation and now (the server's receipt). */
    public static function bounded(Appointment $appointment, CarbonImmutable $at): CarbonImmutable
    {
        $made = CarbonImmutable::parse($appointment->created_at)->utc();
        $now = CarbonImmutable::now();

        return $at->lessThan($made) ? $made : ($at->greaterThan($now) ? $now : $at);
    }

    public function checkIn(Appointment $appointment, ?User $by, ?CarbonImmutable $at = null): Appointment
    {
        $at = $at === null ? null : self::bounded($appointment, $at);

        return $this->step($appointment, [Appointment::BOOKED], fn (Appointment $a) => $a->forceFill([
            'status' => Appointment::CHECKED_IN, 'checked_in_at' => $at ?? now(),
        ])->save(), 'check_in', $by);
    }

    public function start(Appointment $appointment, ?User $by, ?CarbonImmutable $at = null): Appointment
    {
        return $this->step($appointment, [Appointment::BOOKED, Appointment::CHECKED_IN], fn (Appointment $a) => $a->forceFill([
            'status' => Appointment::IN_SERVICE, 'checked_in_at' => $a->checked_in_at ?? $at ?? now(), 'started_at' => $at ?? now(),
        ])->save(), 'start', $by);
    }

    public function finish(Appointment $appointment, ?User $by, ?CarbonImmutable $at = null): Appointment
    {
        return $this->step($appointment, [Appointment::BOOKED, Appointment::CHECKED_IN, Appointment::IN_SERVICE], function (Appointment $a) use ($at) {
            $a->forceFill([
                'status' => Appointment::COMPLETED, 'checked_in_at' => $a->checked_in_at ?? $at ?? now(), 'started_at' => $a->started_at ?? $at ?? now(), 'finished_at' => $at ?? now(),
            ])->save();
            $this->consumption->post($a);
        }, 'finish', $by);
    }

    /**
     * SAL-10: cancels a booking. Inside the company's window before the start a late cancellation makes a fee; a
     * client already in the chair (checked in) is not charged one.
     */
    public function cancel(Appointment $appointment, string $reason, ?User $by, ?CarbonImmutable $at = null, bool $withFee = true): Appointment
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($appointment, $reason, $by, $at, $withFee) {
            $a = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($a->status === Appointment::CANCELLED) {
                return $a;
            }

            if (! in_array($a->status, Appointment::OPEN, true)) {
                throw Errors::make(409, 'appointment_not_open');
            }

            $settings = Settings::for($a->company_id);
            $now = $at === null ? CarbonImmutable::now() : self::bounded($a, $at);
            $late = $withFee && $a->status === Appointment::BOOKED && $a->starts_at->lessThan($now->addHours($settings['cancel_window_hours']));
            $fee = $late ? Bookings::percentOf((int) $a->total_minor, $settings['late_cancel_fee_percent']) : 0;

            $a->forceFill([
                'status' => Appointment::CANCELLED, 'cancelled_at' => $now, 'cancelled_by' => $by?->id, 'cancel_reason' => mb_substr($reason, 0, 200),
                'fee_minor' => $fee, 'fee_status' => $fee > 0 ? 'due' : 'none', 'fee_reason' => $fee > 0 ? 'late_cancel' : null,
            ])->save();
            Line::query()->where('appointment_id', $a->id)->update(['holds' => false]);
            $this->auditor->record('salon.appointment.cancel', $a, null, ['reason' => $reason, 'fee_minor' => $fee]);
            $this->afterCommit(fn () => $this->notices->send(Notices::CANCELLED, $a->refresh()));

            return $a;
        });
    }

    /** SAL-10: marks a booking nobody came to; allowed once its start has passed. The no-show fee follows. */
    public function noShow(Appointment $appointment, string $reason, ?User $by): Appointment
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($appointment, $reason, $by) {
            $a = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($a->status === Appointment::NO_SHOW) {
                return $a;
            }

            if ($a->status !== Appointment::BOOKED) {
                throw Errors::make(409, 'appointment_not_open');
            }

            if ($a->starts_at->greaterThan(now())) {
                throw Errors::make(409, 'too_early');
            }

            $fee = Bookings::percentOf((int) $a->total_minor, Settings::for($a->company_id)['no_show_fee_percent']);
            $a->forceFill([
                'status' => Appointment::NO_SHOW, 'cancelled_at' => now(), 'cancelled_by' => $by?->id, 'cancel_reason' => mb_substr($reason, 0, 200),
                'fee_minor' => $fee, 'fee_status' => $fee > 0 ? 'due' : 'none', 'fee_reason' => $fee > 0 ? 'no_show' : null,
            ])->save();
            Line::query()->where('appointment_id', $a->id)->update(['holds' => false]);
            $this->auditor->record('salon.appointment.no_show', $a, null, ['reason' => $reason, 'fee_minor' => $fee]);

            return $a;
        });
    }

    /** SAL-10: a fee waived, with a reason, by someone who may (the request checked `salon.fee.waive`). */
    public function waiveFee(Appointment $appointment, string $reason, User $by): Appointment
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($appointment, $reason, $by) {
            $a = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($a->fee_status === 'waived') {
                return $a;
            }

            if ($a->fee_status !== 'due') {
                throw Errors::make(409, 'no_fee_due');
            }

            $a->forceFill(['fee_status' => 'waived', 'fee_waive_reason' => mb_substr($reason, 0, 200), 'fee_waived_by' => $by->id])->save();
            $this->auditor->record('salon.fee.waive', $a, null, ['reason' => $reason, 'fee_minor' => $a->fee_minor]);

            return $a;
        });
    }

    /** @param list<string> $from the statuses the step can start from */
    private function step(Appointment $appointment, array $from, callable $apply, string $verb, ?User $by): Appointment
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($appointment, $from, $apply, $verb, $by) {
            $a = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($a->status, $from, true)) {
                // Already past this step (a repeat or a late record): nothing to do. A cancelled or no-show booking cannot go on.
                if (in_array($a->status, [Appointment::CANCELLED, Appointment::NO_SHOW], true)) {
                    throw Errors::make(409, 'appointment_not_open');
                }

                return $a;
            }

            $apply($a);
            $this->auditor->record("salon.appointment.{$verb}", $a, null, ['by' => $by?->id]);

            return $a->refresh();
        });
    }

    private function afterCommit(callable $fn): void
    {
        DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => rescue($fn, report: true));
    }
}
