<?php

namespace Modules\Hotel\Availability;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\HotelError;

/**
 * HOT-11, HOT-13: what can be sold. For a night, a room type's capacity is
 * its rooms that are not archived and not out of order that night; the
 * stays holding a room (`reserved` or `checked_in`) that night use it up. A
 * stay counts against the type of the room it holds when it has one (an
 * upgrade uses the better room), else its booked type.
 *
 * No overbooking: everything that creates, moves or extends a stay, or
 * assigns a room, first takes `lock()` (a transaction-level advisory lock per
 * branch and room type, or per room) and counts again inside it, so two
 * requests for the last room are serialised and only one succeeds.
 */
class Availability
{
    /** Take the locks for $typeIds at $branchId, in a fixed order (no deadlocks). @param  list<string>  $typeIds */
    public function lock(string $branchId, array $typeIds): void
    {
        $typeIds = array_values(array_unique($typeIds));
        sort($typeIds);

        foreach ($typeIds as $typeId) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["hotel:avail:{$branchId}:{$typeId}"]);
        }
    }

    /** Lock one room's assignment for the rest of the transaction. */
    public function lockRoom(string $roomId): void
    {
        DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["hotel:room:{$roomId}"]);
    }

    /**
     * Free rooms of $typeId for every night of [arrival, departure): the least free on any night.
     * Call inside lock() when the answer decides a write.
     */
    public function free(string $branchId, string $typeId, CarbonImmutable $arrival, CarbonImmutable $departure, ?string $exceptStayId = null): int
    {
        $least = PHP_INT_MAX;

        foreach ($this->nights($branchId, $typeId, $arrival, $departure, $exceptStayId) as $night) {
            $least = min($least, $night['free']);
        }

        return $least === PHP_INT_MAX ? 0 : $least;
    }

    /** Refuse (409 `not_available`) unless $count rooms of the type are free every night. */
    public function assertFree(string $branchId, string $typeId, CarbonImmutable $arrival, CarbonImmutable $departure, int $count = 1, ?string $exceptStayId = null): void
    {
        if ($this->free($branchId, $typeId, $arrival, $departure, $exceptStayId) < $count) {
            throw HotelError::make(409, 'not_available', field: 'room_type_id');
        }
    }

    /**
     * Per night: sellable rooms, rooms out of order, stays holding, free.
     *
     * @return list<array{date: string, rooms: int, out_of_order: int, booked: int, free: int}>
     */
    public function nights(string $branchId, string $typeId, CarbonImmutable $from, CarbonImmutable $to, ?string $exceptStayId = null): array
    {
        $rooms = Room::query()->where('branch_id', $branchId)->where('room_type_id', $typeId)->whereNull('archived_at')->get(['id', 'status', 'ooo_from', 'ooo_until']);
        $stays = $this->holding($branchId, $from, $to, $exceptStayId)->filter(fn (object $s) => $s->effective_type_id === $typeId);
        $rows = [];

        for ($day = $from; $day < $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $out = $rooms->filter(fn (Room $r) => $this->outOfOrderOn($r, $date))->count();
            $booked = $stays->filter(fn (object $s) => $s->arrival_on <= $date && $s->departure_on > $date)->count();
            $rows[] = ['date' => $date, 'rooms' => $rooms->count() - $out, 'out_of_order' => $out, 'booked' => $booked, 'free' => max(-$booked, $rooms->count() - $out - $booked)];
        }

        return $rows;
    }

    /** HOT-11: the grid of a property: per room type its nights, and per room the stays that hold it. */
    public function grid(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $types = RoomType::query()->whereNull('archived_at')->where('company_id', $this->companyOf($branchId))->orderBy('sort')->orderBy('code')->get();
        $roomRows = Room::query()->where('branch_id', $branchId)->whereNull('archived_at')->orderBy('floor')->orderBy('number')->get();
        $stays = Stay::query()->where('branch_id', $branchId)->whereIn('status', Stay::HOLDING)
            ->where('arrival_on', '<', $to->toDateString())->where('departure_on', '>', $from->toDateString())
            ->with('guest:id,name')->orderBy('arrival_on')->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'types' => $types->map(fn (RoomType $type) => [
                'id' => $type->id, 'code' => $type->code, 'name' => $type->name,
                'nights' => $this->nights($branchId, $type->id, $from, $to),
            ])->values()->all(),
            'rooms' => $roomRows->map(fn (Room $room) => [
                'id' => $room->id, 'number' => $room->number, 'floor' => $room->floor, 'room_type_id' => $room->room_type_id, 'status' => $room->status,
                'out_of_order' => $room->status === Room::OUT_OF_ORDER ? ['from' => $room->ooo_from?->toDateString(), 'until' => $room->ooo_until?->toDateString()] : null,
                'stays' => $stays->where('room_id', $room->id)->map(fn (Stay $stay) => [
                    'id' => $stay->id, 'reservation_id' => $stay->reservation_id, 'guest' => $stay->guest?->name, 'status' => $stay->status,
                    'arrival_on' => $stay->arrival_on->toDateString(), 'departure_on' => $stay->departure_on->toDateString(),
                ])->values()->all(),
            ])->values()->all(),
            // Stays booked by type that have no room yet.
            'unassigned' => $stays->whereNull('room_id')->map(fn (Stay $stay) => [
                'id' => $stay->id, 'reservation_id' => $stay->reservation_id, 'guest' => $stay->guest?->name, 'room_type_id' => $stay->room_type_id,
                'arrival_on' => $stay->arrival_on->toDateString(), 'departure_on' => $stay->departure_on->toDateString(),
            ])->values()->all(),
        ];
    }

    /** Whether a room is out of order on $date (Y-m-d): out of order status, within its dates. */
    public function outOfOrderOn(Room $room, string $date): bool
    {
        return $room->status === Room::OUT_OF_ORDER
            && ($room->ooo_from === null || $room->ooo_from->toDateString() <= $date)
            && ($room->ooo_until === null || $room->ooo_until->toDateString() >= $date);
    }

    /** Whether another stay holds $roomId on any night of [arrival, departure). */
    public function roomTaken(string $roomId, CarbonImmutable $arrival, CarbonImmutable $departure, ?string $exceptStayId = null): bool
    {
        return Stay::query()->where('room_id', $roomId)->whereIn('status', Stay::HOLDING)
            ->where('arrival_on', '<', $departure->toDateString())->where('departure_on', '>', $arrival->toDateString())
            ->when($exceptStayId !== null, fn ($q) => $q->where('id', '<>', $exceptStayId))->exists();
    }

    /** @return Collection<int, object> stays holding a room in the range with the type they use up */
    private function holding(string $branchId, CarbonImmutable $from, CarbonImmutable $to, ?string $exceptStayId)
    {
        return DB::connection(TenantContext::CONNECTION)->table('hotel_stays as s')
            ->leftJoin('hotel_rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.branch_id', $branchId)->whereIn('s.status', Stay::HOLDING)
            ->where('s.arrival_on', '<', $to->toDateString())->where('s.departure_on', '>', $from->toDateString())
            ->when($exceptStayId !== null, fn ($q) => $q->where('s.id', '<>', $exceptStayId))
            ->get(['s.id', 's.arrival_on', 's.departure_on', DB::raw('coalesce(r.room_type_id, s.room_type_id) as effective_type_id')]);
    }

    private function companyOf(string $branchId): string
    {
        return (string) DB::connection(TenantContext::CONNECTION)->table('branches')->where('id', $branchId)->value('company_id');
    }
}
