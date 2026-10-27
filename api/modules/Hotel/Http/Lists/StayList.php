<?php

namespace Modules\Hotel\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Http\Resources\StayResource;

/** HOT-12, HOT-31: stays with their booking: the reservations list and the arrivals, departures, in-house and no-show reports. */
class StayList extends HotelList
{
    public const RELATIONS = ['reservation', 'guest:id,name', 'roomType:id,code,name', 'ratePlan:id,code,name', 'room:id,number,status', 'folio:id,stay_id'];

    public function name(): string
    {
        return 'hotel-stays';
    }

    public function auditAction(): string
    {
        return 'hotel.stay.export';
    }

    public function title(array $filters): string
    {
        return __('hotel.lists.stays');
    }

    public function resource(Model $model): JsonResource
    {
        return StayResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'arrival_on' => ListSort::column('arrival_on'),
            'departure_on' => ListSort::column('departure_on'),
            'status' => ListSort::column('status'),
            'created_at' => ListSort::column('created_at'),
            'total' => ListSort::column('total_minor', ['total_minor']),
        ];
    }

    public function defaultSort(): string
    {
        return 'arrival_on';
    }

    public function columns(): array
    {
        return [
            self::col('reservation', ['reservation_number'], fn (array $row) => $row['reservation_number'] ?? null),
            self::col('guest', ['guest'], fn (array $row) => $row['guest']['name'] ?? null),
            self::col('room', ['room'], fn (array $row) => $row['room']['number'] ?? null),
            self::col('room_type', ['room_type'], fn (array $row) => $row['room_type']['name'] ?? null),
            self::day('arrival_on'),
            self::day('departure_on'),
            ListColumn::make('nights', 'hotel.columns.nights', ['nights'], fn (array $row) => (string) $row['nights']),
            ListColumn::make('party', 'hotel.columns.party', ['adults', 'children'], fn (array $row) => $row['adults'].'+'.$row['children']),
            self::col('board', ['board'], fn (array $row) => __('hotel.boards.'.$row['board'])),
            self::col('status', ['status'], fn (array $row) => __('hotel.states.'.$row['status'])),
            self::amount('total_minor'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
