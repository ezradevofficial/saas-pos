<?php

namespace Modules\Hotel\Stays;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Availability\Availability;
use Modules\Hotel\Events\StayCheckedIn;
use Modules\Hotel\Events\StayCheckedOut;
use Modules\Hotel\Folios\FolioPayments;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Folios\Settlement;
use Modules\Hotel\Housekeeping\Housekeeping;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Reservations\Reservations;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Properties;
use Modules\Hotel\Support\Text;

/**
 * HOT-17 to HOT-19, HOT-37: the front desk. Assigning a room, checking in
 * (key handover, the early check-in fee, deposits applied), checking out
 * (the late fee, payments, settlement into the invoice, the room made dirty
 * with a clean task) and moving a guest to another room. Everything runs
 * under the property's availability locks and the room's lock, so two
 * desks cannot give the same room to two guests (HOT-13).
 */
class Stays
{
    public function __construct(
        private readonly Availability $availability,
        private readonly Folios $folios,
        private readonly FolioPayments $payments,
        private readonly Settlement $settlement,
        private readonly Housekeeping $housekeeping,
        private readonly Reservations $reservations,
        private readonly Properties $properties,
        private readonly Auditor $auditor,
    ) {}

    /** Give a reserved stay a room (HOT-17). A room of a better or lesser type is an upgrade or downgrade, recorded. */
    public function assignRoom(Stay $stay, string $roomId, User $by): Stay
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($stay, $roomId) {
            $room = Room::query()->find($roomId);

            if ($room === null || $room->branch_id !== $stay->branch_id || $room->archived_at !== null) {
                throw HotelError::make(422, 'room_unknown', field: 'room_id');
            }

            $this->availability->lock($stay->branch_id, [$stay->room_type_id, $room->room_type_id]);
            $this->availability->lockRoom($room->id);
            $stay = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if (! $stay->holdsRoom()) {
                throw HotelError::make(409, 'stay_not_open');
            }

            for ($day = CarbonImmutable::parse($stay->arrival_on->toDateString()); $day < $stay->departure_on; $day = $day->addDay()) {
                if ($this->availability->outOfOrderOn($room, $day->toDateString())) {
                    throw HotelError::make(409, 'room_out_of_order', field: 'room_id');
                }
            }

            if ($this->availability->roomTaken($room->id, $stay->arrival_on, $stay->departure_on, $stay->id)) {
                throw HotelError::make(409, 'room_taken', field: 'room_id');
            }

            // A room of another type uses that type's capacity: it must have a room to spare.
            if ($room->room_type_id !== $stay->room_type_id) {
                $this->availability->assertFree($stay->branch_id, $room->room_type_id, $stay->arrival_on, $stay->departure_on, 1, $stay->id);
            }

            $before = $stay->room_id;
            $stay->forceFill(['room_id' => $room->id])->save();
            $this->auditor->record('hotel.stay.assign_room', $stay, ['room_id' => $before], [
                'room_id' => $room->id, 'room_number' => $room->number, 'type_changed' => $room->room_type_id !== $stay->room_type_id,
            ]);

            return $stay->refresh();
        });
    }

    /**
     * HOT-17, HOT-18: check a guest in. `$in`: room_id?, keys_issued?, waive_fees?; `$early` says the user may check in before the booked
     * arrival date (the stay then starts today). The guest's ID is required when the company's settings say so.
     *
     * @param  array{room_id?: ?string, keys_issued?: ?int, waive_fees?: bool}  $in
     */
    public function checkIn(Stay $stay, array $in, User $by, bool $early = false): Stay
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($stay, $in, $by, $early) {
            $stay = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if ($stay->status !== Stay::RESERVED) {
                throw HotelError::make(409, 'stay_not_reserved');
            }

            $today = $this->properties->businessDate($stay->branch_id);

            if ($stay->arrival_on->toDateString() > $today) {
                if (! $early) {
                    throw HotelError::make(409, 'arrival_in_future');
                }

                $stay = $this->reservations->modify($stay, ['arrival_on' => $today], $by);
            }

            $settings = Settings::for($stay->company_id);
            $guest = Guest::query()->findOrFail($stay->guest_id);

            if ($settings->require_guest_id && ($guest->id_number === null || $guest->id_number === '')) {
                throw HotelError::make(422, 'guest_id_required', field: 'guest_id');
            }

            if (! empty($in['room_id'])) {
                $stay = $this->assignRoom($stay, (string) $in['room_id'], $by);
            }

            if ($stay->room_id === null) {
                throw HotelError::make(422, 'room_required', field: 'room_id');
            }

            $this->availability->lockRoom($stay->room_id);
            $room = Room::query()->whereKey($stay->room_id)->lockForUpdate()->firstOrFail();

            if ($room->status === Room::OUT_OF_ORDER) {
                throw HotelError::make(409, 'room_out_of_order', field: 'room_id');
            }

            if ($room->status !== Room::VACANT_CLEAN) {
                throw HotelError::make(409, 'room_not_ready', ['status' => $room->status], 'room_id');
            }

            $folio = $this->folios->guestFolio($stay);
            $stay->forceFill(['status' => Stay::CHECKED_IN, 'checked_in_at' => now(), 'keys_issued' => (int) ($in['keys_issued'] ?? 1)])->save();
            $this->housekeeping->setStatus($room, Room::OCCUPIED, $by);

            if (! ($in['waive_fees'] ?? false)) {
                $this->earlyFee($stay, $settings, $today, $by);
            }

            $this->payments->applyDeposits($folio);

            if ($stay->bill_to_master) {
                $master = Folio::query()->where('reservation_id', $stay->reservation_id)->where('kind', Folio::MASTER)->first();

                if ($master !== null) {
                    $this->payments->applyDeposits($master);
                }
            }

            $this->auditor->record('hotel.stay.check_in', $stay, ['status' => Stay::RESERVED], [
                'status' => Stay::CHECKED_IN, 'room_id' => $room->id, 'room_number' => $room->number, 'keys_issued' => $stay->keys_issued, 'fees_waived' => (bool) ($in['waive_fees'] ?? false),
            ]);
            StayCheckedIn::dispatch($stay->tenant_id, $stay->id, $stay->branch_id, $room->id, $folio->id);

            return $stay->refresh();
        });
    }

    /**
     * HOT-19: check a guest out. Takes the payments given (each `{payment_method_id, amount_minor, reference?}`), posts the late fee, settles
     * the folio and makes the room dirty with a clean task. `$allowBalance` lets a user with `hotel.folio.settle_open` leave a balance on
     * account (kept, flagged); otherwise a balance owed refuses the check-out (409 `folio_balance`).
     *
     * @param  array{payments?: list<array<string, mixed>>, waive_fees?: bool}  $in
     * @return array{stay: Stay, folio: Folio}
     */
    public function checkOut(Stay $stay, array $in, User $by, bool $allowBalance = false): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($stay, $in, $by, $allowBalance) {
            $stay = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if ($stay->status !== Stay::CHECKED_IN) {
                throw HotelError::make(409, 'stay_not_in_house');
            }

            $today = $this->properties->businessDate($stay->branch_id);
            $settings = Settings::for($stay->company_id);
            $own = $this->folios->guestFolio($stay);
            $billing = $this->folios->billingFolio($stay);

            if (! ($in['waive_fees'] ?? false)) {
                $this->lateFee($stay, $settings, $today, $by);
            }

            foreach ($in['payments'] ?? [] as $payment) {
                $this->payments->take($billing, [...$payment, 'kind' => 'payment', 'mode' => 'direct'], $by);
            }

            // Charges of a master-billed stay settle with the group; its own folio settles when nothing is left on it.
            $folio = $this->settlement->settle($own, $by, $allowBalance);
            $stay->forceFill(['status' => Stay::CHECKED_OUT, 'checked_out_at' => now()])->save();
            $room = Room::query()->whereKey($stay->room_id)->lockForUpdate()->firstOrFail();
            $this->housekeeping->setStatus($room, Room::VACANT_DIRTY, $by);
            $this->housekeeping->ensureTask($room, 'checkout_clean', $today, $by);

            $this->auditor->record('hotel.stay.check_out', $stay, ['status' => Stay::CHECKED_IN], [
                'status' => Stay::CHECKED_OUT, 'room_id' => $room->id, 'invoice_number' => $folio->invoice_number, 'balance_left_minor' => $folio->balance_left_minor,
            ]);
            StayCheckedOut::dispatch($stay->tenant_id, $stay->id, $stay->branch_id, $room->id, $folio->id);
            $this->settleMasterWhenDone($stay, $by, $allowBalance);

            return ['stay' => $stay->refresh(), 'folio' => $folio];
        });
    }

    /**
     * HOT-37: move an in-house guest to another room. The old room is made dirty (with a clean task), the new one occupied; the rate stays
     * as booked, and `rate_effect` records an upgrade or downgrade for the front desk.
     */
    public function moveRoom(Stay $stay, string $roomId, string $reason, User $by): Stay
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($stay, $roomId, $reason, $by) {
            $new = Room::query()->find($roomId);

            if ($new === null || $new->branch_id !== $stay->branch_id || $new->archived_at !== null) {
                throw HotelError::make(422, 'room_unknown', field: 'room_id');
            }

            $this->availability->lock($stay->branch_id, [$stay->room_type_id, $new->room_type_id]);
            $stay = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if ($stay->status !== Stay::CHECKED_IN || $stay->room_id === null) {
                throw HotelError::make(409, 'stay_not_in_house');
            }

            if ($stay->room_id === $new->id) {
                throw HotelError::make(422, 'room_same', field: 'room_id');
            }

            $ids = [$stay->room_id, $new->id];
            sort($ids);

            foreach ($ids as $id) {
                $this->availability->lockRoom($id);
            }

            $new = Room::query()->whereKey($new->id)->lockForUpdate()->firstOrFail();
            $old = Room::query()->whereKey($stay->room_id)->lockForUpdate()->firstOrFail();

            if ($new->status !== Room::VACANT_CLEAN || $this->availability->roomTaken($new->id, $stay->arrival_on, $stay->departure_on, $stay->id)) {
                throw HotelError::make(409, 'room_not_ready', ['status' => $new->status], 'room_id');
            }

            if ($new->room_type_id !== $old->room_type_id) {
                $this->availability->assertFree($stay->branch_id, $new->room_type_id, $stay->arrival_on, $stay->departure_on, 1, $stay->id);
            }

            $stay->forceFill(['room_id' => $new->id])->save();
            $this->housekeeping->setStatus($old, Room::VACANT_DIRTY, $by);
            $this->housekeeping->ensureTask($old, 'checkout_clean', null, $by);
            $this->housekeeping->setStatus($new, Room::OCCUPIED, $by);
            $this->auditor->record('hotel.stay.move_room', $stay, ['room_id' => $old->id, 'room_number' => $old->number], [
                'room_id' => $new->id, 'room_number' => $new->number, 'reason' => $reason, 'rate_effect' => $new->room_type_id === $old->room_type_id ? 'none' : 'type_changed',
            ]);

            return $stay->refresh();
        });
    }

    private function earlyFee(Stay $stay, Settings $settings, string $today, User $by): void
    {
        if ($settings->early_checkin_fee_minor > 0 && $stay->arrival_on->toDateString() === $today && $this->beforeTime($stay, $settings->check_in_time)) {
            $this->fee($stay, $settings->early_checkin_fee_minor, 'hotel.lines.early_checkin_fee', 'early_checkin', $today, $by);
        }
    }

    private function lateFee(Stay $stay, Settings $settings, string $today, User $by): void
    {
        if ($settings->late_checkout_fee_minor > 0 && $stay->departure_on->toDateString() <= $today && $this->afterTime($stay, $settings->check_out_time)) {
            $this->fee($stay, $settings->late_checkout_fee_minor, 'hotel.lines.late_checkout_fee', 'late_checkout', $today, $by);
        }
    }

    private function fee(Stay $stay, int $amount, string $text, string $source, string $today, User $by): void
    {
        $this->folios->post($this->folios->billingFolio($stay), [
            'kind' => FolioLine::FEE,
            'description' => Text::line($text),
            'amount_minor' => $amount,
            'source' => $source,
            'source_id' => $stay->id,
            'business_date' => $today,
            'created_by' => $by->id,
        ]);
    }

    private function beforeTime(Stay $stay, string $time): bool
    {
        return $this->localNow($stay)->format('H:i') < $time;
    }

    private function afterTime(Stay $stay, string $time): bool
    {
        return $this->localNow($stay)->format('H:i') > $time;
    }

    private function localNow(Stay $stay): CarbonImmutable
    {
        return CarbonImmutable::now(CompanyClock::timezone($stay->company_id, $stay->branch_id));
    }

    /** HOT-26: when the last stay of a group has gone, the master folio settles. */
    private function settleMasterWhenDone(Stay $stay, User $by, bool $allowBalance): void
    {
        $master = Folio::query()->where('reservation_id', $stay->reservation_id)->where('kind', Folio::MASTER)->first();

        if ($master === null || ! $master->isOpen()) {
            return;
        }

        $remaining = Stay::query()->where('reservation_id', $stay->reservation_id)->whereIn('status', Stay::HOLDING)->exists();

        if (! $remaining && ($this->folios->balance($master) === 0 || $allowBalance)) {
            $this->settlement->settle($master, $by, $allowBalance);
        }
    }
}
