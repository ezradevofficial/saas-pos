<?php

namespace Modules\Hotel\Stays;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\Reservation;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Reservations\Reservations;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Properties;

/**
 * HOT-12, HOT-17: a guest at the desk now. One transaction books the room's
 * type for the property's business date through Reservations (so availability,
 * the rate quote and the locks are the same) and checks the guest in.
 */
class WalkIns
{
    public function __construct(
        private readonly Reservations $reservations,
        private readonly Stays $stays,
        private readonly Properties $properties,
    ) {}

    /** @param array<string, mixed> $data branch_id, guest_id | guest, room_id, rate_plan_id, departure_on, adults?, children?, rate_minor?, rate_reason?, keys_issued?, waive_fees?, source?, notes? */
    public function create(array $data, User $by): Reservation
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $by) {
            $room = Room::query()->find($data['room_id']);

            if ($room === null || $room->branch_id !== $data['branch_id'] || $room->archived_at !== null) {
                throw HotelError::make(422, 'room_unknown', field: 'room_id');
            }

            $plan = RatePlan::query()->find($data['rate_plan_id']);

            if ($plan === null || $plan->room_type_id !== $room->room_type_id) {
                throw HotelError::make(422, 'rate_plan_unavailable', field: 'rate_plan_id');
            }

            $reservation = $this->reservations->create([
                'branch_id' => $data['branch_id'],
                'kind' => Reservation::WALK_IN,
                'guest_id' => $data['guest_id'] ?? null,
                'guest' => $data['guest'] ?? null,
                'source' => $data['source'] ?? 'walk_in',
                'notes' => $data['notes'] ?? null,
                'stays' => [array_filter([
                    'room_type_id' => $room->room_type_id,
                    'rate_plan_id' => $plan->id,
                    'arrival_on' => $this->properties->businessDate($data['branch_id']),
                    'departure_on' => $data['departure_on'],
                    'adults' => $data['adults'] ?? 1,
                    'children' => $data['children'] ?? 0,
                    'rate_minor' => $data['rate_minor'] ?? null,
                    'rate_reason' => $data['rate_reason'] ?? null,
                ], fn ($value) => $value !== null)],
            ], $by);

            $this->stays->checkIn($reservation->stays->first(), ['room_id' => $room->id, 'keys_issued' => $data['keys_issued'] ?? 1, 'waive_fees' => $data['waive_fees'] ?? false], $by);

            return $reservation->refresh()->load(['guest', 'stays.roomType', 'stays.ratePlan', 'stays.room', 'folios']);
        });
    }
}
