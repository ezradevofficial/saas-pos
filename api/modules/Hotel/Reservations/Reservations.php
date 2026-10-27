<?php

namespace Modules\Hotel\Reservations;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Availability\Availability;
use Modules\Hotel\Folios\FolioPayments;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Folios\Settlement;
use Modules\Hotel\Guests\Guests;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\Reservation;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Rates\RateQuotes;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Properties;
use Modules\Hotel\Support\Text;

/**
 * HOT-12 to HOT-15, HOT-37: bookings and what changes them before and
 * after arrival.
 *
 * - create(): a reservation of one or more stays (direct, walk-in, group),
 *   priced by RateQuotes and stored with its quoted nights, each with a guest
 *   folio, a master folio for a group. Availability is taken under the
 *   branch-and-type locks and counted again inside them (HOT-13), so the
 *   last room goes to one request only.
 * - modify(): dates, party, plan or type of a reserved stay; the departure of
 *   one in house (extend, shorten). Availability and the room are re-checked.
 * - cancel() and noShow(): the configured charge (HOT-15) posts to the stay's
 *   folio as a fee line, the deposit is applied against it, the room is let go.
 */
class Reservations
{
    public const NUMBER_TYPE = 'hotel.reservation';

    public const NUMBER = 'RS-{BRANCH}-{YYYY}-{00001}';

    public function __construct(
        private readonly Availability $availability,
        private readonly RateQuotes $quotes,
        private readonly Folios $folios,
        private readonly FolioPayments $payments,
        private readonly Settlement $settlement,
        private readonly Guests $guests,
        private readonly Properties $properties,
        private readonly Numbering $numbering,
        private readonly Auditor $auditor,
    ) {}

    public static function numberType(): DocumentNumberType
    {
        return new DocumentNumberType(self::NUMBER_TYPE, HotelServiceProvider::MODULE, self::NUMBER, NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'hotel.numbering.reservation');
    }

    /**
     * @param  array{branch_id: string, kind?: string, guest_id?: ?string, guest?: array<string, mixed>, group_name?: ?string, source?: string, notes?: ?string, master_folio?: bool, stays: list<array<string, mixed>>}  $data
     */
    public function create(array $data, User $by): Reservation
    {
        $branch = $this->properties->require($data['branch_id']);
        $kind = (string) ($data['kind'] ?? Reservation::DIRECT);
        $units = $this->units($data['stays'], $kind);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $by, $branch, $kind, $units) {
            $this->availability->lock($branch->id, array_column($units, 'room_type_id'));
            $today = $this->properties->businessDate($branch->id);
            $settings = Settings::for($branch->company_id);

            $guestId = $data['guest_id'] ?? $this->guests->create($branch->company_id, $data['guest'], $by)->id;
            $guest = Guest::query()->find($guestId);

            if ($guest === null || $guest->company_id !== $branch->company_id || $guest->archived_at !== null) {
                throw HotelError::make(422, 'guest_unknown', field: 'guest_id');
            }

            $reservation = Reservation::create([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'number' => $this->numbering->next(self::NUMBER_TYPE, new NumberContext(Company::query()->findOrFail($branch->company_id), $branch))->number,
                'kind' => $kind,
                'guest_id' => $guest->id,
                'group_name' => $data['group_name'] ?? null,
                'source' => $data['source'] ?? ($kind === Reservation::WALK_IN ? 'walk_in' : 'direct'),
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);
            $stays = [];

            foreach ($units as $unit) {
                $stays[] = $this->makeStay($reservation, $unit, $settings, $today, $by);
            }

            $currency = $stays[0]->currency;
            $master = $kind === Reservation::GROUP && ($data['master_folio'] ?? true) ? $this->folios->masterFolio($reservation, $currency) : null;

            foreach ($stays as $stay) {
                $this->folios->guestFolio($stay);

                if ($master !== null && $master->currency !== $stay->currency) {
                    throw HotelError::make(422, 'group_currency', field: 'stays');
                }
            }

            $this->auditor->record('hotel.reservation.create', $reservation, null, [
                'number' => $reservation->number, 'kind' => $kind, 'stays' => count($stays),
                'nights' => array_sum(array_map(fn (Stay $s) => $s->nights(), $stays)), 'total_minor' => array_sum(array_map(fn (Stay $s) => $s->total_minor, $stays)),
            ]);

            return $reservation->refresh()->load(['guest', 'stays.roomType', 'stays.ratePlan', 'stays.room', 'folios']);
        });
    }

    /**
     * HOT-37: change a stay. A reserved stay may change dates, party, plan, room type and notes; one in house only its departure,
     * party and notes (nights already stayed keep their rate).
     *
     * @param  array<string, mixed>  $data
     */
    public function modify(Stay $stay, array $data, User $by): Stay
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($stay, $data) {
            $this->availability->lock($stay->branch_id, [$stay->room_type_id, $data['room_type_id'] ?? $stay->room_type_id]);
            $stay = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $inHouse = $stay->status === Stay::CHECKED_IN;

            if (! $stay->holdsRoom()) {
                throw HotelError::make(409, 'stay_not_open');
            }

            if ($inHouse && array_intersect(['arrival_on', 'room_type_id', 'rate_plan_id'], array_keys($data)) !== []) {
                throw HotelError::make(409, 'stay_in_house_fields');
            }

            $settings = Settings::for($stay->company_id);
            $today = $this->properties->businessDate($stay->branch_id);
            $arrival = CarbonImmutable::parse($data['arrival_on'] ?? $stay->arrival_on->toDateString());
            $departure = CarbonImmutable::parse($data['departure_on'] ?? $stay->departure_on->toDateString());
            $typeId = (string) ($data['room_type_id'] ?? $stay->room_type_id);
            $planId = (string) ($data['rate_plan_id'] ?? $stay->rate_plan_id);
            $adults = (int) ($data['adults'] ?? $stay->adults);
            $children = (int) ($data['children'] ?? $stay->children);

            if (! $inHouse && $arrival->toDateString() < $today) {
                throw HotelError::make(422, 'arrival_in_past', field: 'arrival_on');
            }

            if ($inHouse && $departure->toDateString() < $today) {
                throw HotelError::make(422, 'departure_in_past', field: 'departure_on');
            }

            $before = ['arrival_on' => $stay->arrival_on->toDateString(), 'departure_on' => $stay->departure_on->toDateString(), 'adults' => $stay->adults, 'children' => $stay->children,
                'room_type_id' => $stay->room_type_id, 'rate_plan_id' => $stay->rate_plan_id, 'total_minor' => $stay->total_minor];
            $type = RoomType::query()->find($typeId);
            $plan = RatePlan::query()->find($planId);

            if ($type === null || $plan === null || $type->company_id !== $stay->company_id || $plan->company_id !== $stay->company_id) {
                throw HotelError::make(422, 'rate_plan_unavailable', field: 'rate_plan_id');
            }

            $moved = $arrival->toDateString() !== $stay->arrival_on->toDateString() || $departure->toDateString() !== $stay->departure_on->toDateString() || $typeId !== $stay->room_type_id;

            if ($moved) {
                $this->availability->assertFree($stay->branch_id, $typeId, $arrival, $departure, 1, $stay->id);
            }

            if ($stay->room_id !== null && $typeId !== $stay->room_type_id && ! $inHouse) {
                $stay->room_id = null;
            }

            if ($stay->room_id !== null && $moved) {
                $this->availability->lockRoom($stay->room_id);

                if ($this->availability->roomTaken($stay->room_id, $arrival, $departure, $stay->id)) {
                    throw HotelError::make(409, 'room_taken', field: 'departure_on');
                }
            }

            $quote = $this->quotes->quote($plan, $type, $settings, $arrival, $departure, $adults, $children, withTax: false);
            $nights = $quote['nights'];

            // Nights already stayed keep what they were priced at.
            if ($inHouse) {
                $kept = array_values(array_filter((array) $stay->nightly, fn (array $night) => $night['date'] < $today && $night['date'] < $departure->toDateString()));
                $nights = [...$kept, ...array_values(array_filter($nights, fn (array $night) => $night['date'] >= $today))];
            }

            $overridden = $stay->rate_overridden;

            if (isset($data['rate_minor'])) {
                $nights = array_map(fn (array $night) => $inHouse && $night['date'] < $today ? $night : [...$night, 'rate_minor' => (int) $data['rate_minor'], 'amount_minor' => (int) $data['rate_minor'] + $night['extra_minor']], $nights);
                $overridden = true;
            }

            $stay->fill([
                'arrival_on' => $arrival->toDateString(), 'departure_on' => $departure->toDateString(), 'room_type_id' => $typeId, 'rate_plan_id' => $planId,
                'adults' => $adults, 'children' => $children, 'board' => $plan->board, 'currency' => $stay->currency,
                'nightly' => $nights, 'total_minor' => array_sum(array_column($nights, 'amount_minor')), 'rate_overridden' => $overridden,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $stay->notes,
                'bill_to_master' => $data['bill_to_master'] ?? $stay->bill_to_master,
            ]);

            if ($plan->currency !== $stay->currency) {
                throw HotelError::make(422, 'stay_currency', field: 'rate_plan_id');
            }

            $stay->save();
            $this->auditor->record('hotel.stay.update', $stay, $before, [
                'arrival_on' => $stay->arrival_on->toDateString(), 'departure_on' => $stay->departure_on->toDateString(), 'adults' => $stay->adults, 'children' => $stay->children,
                'room_type_id' => $stay->room_type_id, 'rate_plan_id' => $stay->rate_plan_id, 'total_minor' => $stay->total_minor,
            ]);

            if (isset($data['rate_minor'])) {
                $this->auditor->record('hotel.stay.rate_override', $stay, null, ['rate_minor' => (int) $data['rate_minor'], 'reason' => $data['rate_reason'] ?? null]);
            }

            return $stay->refresh();
        });
    }

    /**
     * HOT-15: cancel a reserved stay. The charge is the configured one for the notice given (none inside the free window); `$waive` skips it
     * (a reason is required, `hotel.fee.waive` is checked by the request). The deposit is applied; when nothing is owed the folio settles.
     */
    public function cancel(Stay $stay, string $reason, bool $waive, User $by): Stay
    {
        return $this->release($stay, Stay::CANCELLED, 'cancel', $reason, $waive, $by);
    }

    /** HOT-15, HOT-30: a stay that did not arrive (the night audit, or the front desk). */
    public function noShow(Stay $stay, ?string $reason, bool $waive, ?User $by): Stay
    {
        return $this->release($stay, Stay::NO_SHOW, 'noshow', $reason, $waive, $by);
    }

    /** What the cancellation or no-show of $stay would cost at the business date: {amount_minor, kind, value, free}. */
    public function penalty(Stay $stay, string $which, ?string $businessDate = null): array
    {
        $settings = Settings::for($stay->company_id);
        $plan = RatePlan::query()->find($stay->rate_plan_id);
        $pick = fn (string $field, mixed $default) => $plan?->{"{$which}_{$field}"} ?? $default;
        $kind = (string) $pick('charge_kind', $settings->{"{$which}_charge_kind"});
        $value = (int) $pick('charge_value', $settings->{"{$which}_charge_value"});
        $freeDays = (int) ($plan?->cancel_free_days ?? $settings->cancel_free_days);
        $date = $businessDate ?? $this->properties->businessDate($stay->branch_id);
        $daysBefore = (int) CarbonImmutable::parse($date)->diffInDays($stay->arrival_on, false);

        if ($which === 'cancel' && $daysBefore >= $freeDays) {
            return ['amount_minor' => 0, 'kind' => $kind, 'value' => $value, 'free' => true];
        }

        $nights = array_column((array) $stay->nightly, 'amount_minor');
        $amount = match ($kind) {
            'nights' => array_sum(array_slice($nights, 0, max(0, $value))),
            'percent' => (int) (string) BigDecimal::of($stay->total_minor)->multipliedBy($value)->dividedBy(100, 0, RoundingMode::HalfUp),
            'fixed' => $value,
            default => 0,
        };

        return ['amount_minor' => (int) $amount, 'kind' => $kind, 'value' => $value, 'free' => false];
    }

    private function release(Stay $stay, string $status, string $which, ?string $reason, bool $waive, ?User $by): Stay
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($stay, $status, $which, $reason, $waive, $by) {
            $stay = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();

            if ($stay->status !== Stay::RESERVED) {
                throw HotelError::make(409, 'stay_not_reserved');
            }

            $today = $this->properties->businessDate($stay->branch_id);

            if ($status === Stay::NO_SHOW && $stay->arrival_on->toDateString() > $today) {
                throw HotelError::make(409, 'no_show_too_early');
            }

            $penalty = $waive ? ['amount_minor' => 0, 'kind' => 'waived', 'value' => 0, 'free' => true] : $this->penalty($stay, $which, $today);
            $folio = $this->folios->billingFolio($stay);

            if ($penalty['amount_minor'] > 0) {
                $this->folios->post($folio, [
                    'kind' => FolioLine::FEE,
                    'description' => Text::line($which === 'cancel' ? 'hotel.lines.cancellation_fee' : 'hotel.lines.no_show_fee', ['reservation' => Reservation::query()->whereKey($stay->reservation_id)->value('number')]),
                    'amount_minor' => $penalty['amount_minor'],
                    'source' => $which === 'cancel' ? 'cancellation' : 'no_show',
                    'source_id' => $stay->id,
                    'business_date' => $today,
                    'flags' => [],
                    'created_by' => $by?->id,
                ]);
            }

            $stay->forceFill([
                'status' => $status,
                'cancelled_at' => $status === Stay::CANCELLED ? now() : $stay->cancelled_at,
                'no_show_at' => $status === Stay::NO_SHOW ? now() : $stay->no_show_at,
                'cancel_reason' => $reason,
            ])->save();

            $own = $this->folios->guestFolio($stay);
            $this->payments->applyDeposits($own);

            if ($own->id !== $folio->id) {
                $this->payments->applyDeposits($folio);
            }

            $this->auditor->record($status === Stay::CANCELLED ? 'hotel.reservation.cancel' : 'hotel.reservation.no_show', $stay, ['status' => Stay::RESERVED], [
                'status' => $status, 'reason' => $reason, 'charge_minor' => $penalty['amount_minor'], 'charge_kind' => $penalty['kind'], 'waived' => $waive,
            ]);

            // Nothing owed and nothing held: close the folio (the fee, if any, becomes its invoice).
            $own->refresh();

            if ($own->isOpen() && $this->folios->balance($own) === 0) {
                $this->settlement->settle($own, $by);
            }

            return $stay->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $stays
     * @return list<array<string, mixed>> one entry per room (a line with `rooms` > 1 is expanded)
     */
    private function units(array $stays, string $kind): array
    {
        $units = [];

        foreach ($stays as $line) {
            for ($i = 0; $i < max(1, (int) ($line['rooms'] ?? 1)); $i++) {
                $units[] = [...$line, 'bill_to_master' => (bool) ($line['bill_to_master'] ?? $kind === Reservation::GROUP)];
            }
        }

        if ($units === [] || ($kind !== Reservation::GROUP && count($units) > 1)) {
            throw HotelError::make(422, 'stays_invalid', field: 'stays');
        }

        if (count($units) > 200) {
            throw HotelError::make(422, 'group_too_large', ['max' => 200], 'stays');
        }

        return $units;
    }

    /** @param array<string, mixed> $unit */
    private function makeStay(Reservation $reservation, array $unit, Settings $settings, string $today, User $by): Stay
    {
        $type = RoomType::query()->find($unit['room_type_id']);
        $plan = RatePlan::query()->find($unit['rate_plan_id']);

        if ($type === null || $plan === null || $type->company_id !== $reservation->company_id || $plan->company_id !== $reservation->company_id) {
            throw HotelError::make(422, 'rate_plan_unavailable', field: 'rate_plan_id');
        }

        $arrival = CarbonImmutable::parse($unit['arrival_on']);
        $departure = CarbonImmutable::parse($unit['departure_on']);

        if ($arrival->toDateString() < $today) {
            throw HotelError::make(422, 'arrival_in_past', field: 'arrival_on');
        }

        $adults = (int) ($unit['adults'] ?? 1);
        $children = (int) ($unit['children'] ?? 0);
        $quote = $this->quotes->quote($plan, $type, $settings, $arrival, $departure, $adults, $children, withTax: false);
        $this->availability->assertFree($reservation->branch_id, $type->id, $arrival, $departure);
        $nights = $quote['nights'];
        $overridden = false;

        if (isset($unit['rate_minor'])) {
            $nights = array_map(fn (array $night) => [...$night, 'rate_minor' => (int) $unit['rate_minor'], 'amount_minor' => (int) $unit['rate_minor'] + $night['extra_minor']], $nights);
            $overridden = true;
        }

        $guestId = $unit['guest_id'] ?? $reservation->guest_id;
        $stay = Stay::create([
            'company_id' => $reservation->company_id,
            'branch_id' => $reservation->branch_id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guestId,
            'room_type_id' => $type->id,
            'rate_plan_id' => $plan->id,
            'room_id' => null,
            'arrival_on' => $arrival->toDateString(),
            'departure_on' => $departure->toDateString(),
            'adults' => $adults,
            'children' => $children,
            'board' => $plan->board,
            'currency' => $plan->currency,
            'nightly' => $nights,
            'total_minor' => array_sum(array_column($nights, 'amount_minor')),
            'rate_overridden' => $overridden,
            'status' => Stay::RESERVED,
            'bill_to_master' => (bool) $unit['bill_to_master'],
            'notes' => $unit['notes'] ?? null,
            'created_by' => $by->id,
        ]);

        if ($overridden) {
            $this->auditor->record('hotel.stay.rate_override', $stay, null, ['rate_minor' => (int) $unit['rate_minor'], 'reason' => $unit['rate_reason'] ?? null]);
        }

        return $stay;
    }
}
