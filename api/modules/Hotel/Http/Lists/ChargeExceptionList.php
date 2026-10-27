<?php

namespace Modules\Hotel\Http\Lists;

use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Http\Resources\ChargeExceptionResource;

/** HOT-22, HOT-31: room charges from tills that could not be posted. */
class ChargeExceptionList extends HotelList
{
    public function name(): string
    {
        return 'hotel-charge-exceptions';
    }

    public function auditAction(): string
    {
        return 'hotel.charge_exception.export';
    }

    public function title(array $filters): string
    {
        return __('hotel.lists.charge_exceptions');
    }

    public function resource(Model $model): JsonResource
    {
        return ChargeExceptionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'status' => ListSort::column('status'),
            'amount' => ListSort::column('amount_minor', ['amount_minor']),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            self::at('created_at'),
            self::col('reference', ['reference'], fn (array $row) => $row['reference'] ?? null),
            self::col('room', ['room_number'], fn (array $row) => $row['room_number'] ?? null),
            self::amount('amount_minor'),
            self::col('reason', ['reason'], fn (array $row) => __('hotel.exception_reasons.'.$row['reason'])),
            self::col('status', ['status'], fn (array $row) => __('hotel.states.'.$row['status'])),
        ];
    }
}
