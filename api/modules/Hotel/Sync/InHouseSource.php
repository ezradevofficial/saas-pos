<?php

namespace Modules\Hotel\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;

/**
 * HOT-35, NFR-04: who is in house at the till's property (`hotel_in_house`,
 * a snapshot), so the room-charge tender works offline: `{id (the stay),
 * room_number, guest_name, folio_id, currency, allowed, limit_minor,
 * balance_minor}`. Only what the cashier needs to confirm the guest: a name,
 * never an ID document, phone or address (NFR-08). The server checks the
 * charge again when the sale arrives (RoomCharges).
 */
class InHouseSource implements SnapshotSource
{
    public function key(): string
    {
        return 'hotel_in_house';
    }

    public function module(): string
    {
        return HotelServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $folios = app(Folios::class);
        $limit = Settings::for($scope->companyId())->charge_limit_minor;
        $stays = Stay::query()->where('branch_id', $scope->branch->id)->where('status', Stay::CHECKED_IN)->whereNotNull('room_id')->get();
        $rooms = Room::query()->whereKey($stays->pluck('room_id')->all())->pluck('number', 'id');
        $guests = Guest::query()->whereKey($stays->pluck('guest_id')->all())->pluck('name', 'id');

        return $stays->map(function (Stay $stay) use ($folios, $rooms, $guests, $limit) {
            $folio = $folios->billingFolio($stay);

            return [
                'id' => $stay->id,
                'room_number' => $rooms[$stay->room_id] ?? '',
                'guest_name' => $guests[$stay->guest_id] ?? '',
                'folio_id' => $folio->id,
                'currency' => $folio->currency,
                'allowed' => $folio->status === Folio::OPEN,
                'limit_minor' => $limit === null ? null : (string) $limit,
                'balance_minor' => (string) $folios->balance($folio),
            ];
        })->sortBy('room_number', SORT_NATURAL)->values()->all();
    }
}
