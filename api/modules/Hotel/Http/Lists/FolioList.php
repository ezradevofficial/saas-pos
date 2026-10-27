<?php

namespace Modules\Hotel\Http\Lists;

use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Http\Resources\FolioResource;

/** HOT-20, HOT-31: folios, and the folio balances report (folios still owing). */
class FolioList extends HotelList
{
    public const RELATIONS = ['reservation:id,number', 'guest:id,name', 'stay.room:id,number'];

    public function name(): string
    {
        return 'hotel-folios';
    }

    public function auditAction(): string
    {
        return 'hotel.folio.export';
    }

    public function title(array $filters): string
    {
        return __('hotel.lists.folios');
    }

    public function resource(Model $model): JsonResource
    {
        return FolioResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'number' => ListSort::column('number'),
            'created_at' => ListSort::column('created_at'),
            'status' => ListSort::column('status'),
            'settled_at' => ListSort::column('settled_at'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            self::col('number', ['number'], fn (array $row) => $row['number']),
            self::col('reservation', ['reservation_number'], fn (array $row) => $row['reservation_number'] ?? null),
            self::col('guest', ['guest'], fn (array $row) => $row['guest']['name'] ?? null),
            self::col('room', ['room_number'], fn (array $row) => $row['room_number'] ?? null),
            self::col('kind', ['kind'], fn (array $row) => __('hotel.kinds.'.$row['kind'])),
            self::col('status', ['status'], fn (array $row) => __('hotel.states.'.$row['status'])),
            self::amount('charges_minor'),
            self::amount('payments_minor'),
            self::amount('balance_minor'),
            self::col('invoice_number', ['invoice_number'], fn (array $row) => $row['invoice_number'] ?? null),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
