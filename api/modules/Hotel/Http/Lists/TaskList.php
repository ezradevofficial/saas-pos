<?php

namespace Modules\Hotel\Http\Lists;

use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Http\Resources\TaskResource;

/** HOT-28, HOT-31: housekeeping tasks, and the housekeeping report. */
class TaskList extends HotelList
{
    public const RELATIONS = ['room:id,number,floor,status', 'assignee:id,name'];

    public function name(): string
    {
        return 'hotel-tasks';
    }

    public function auditAction(): string
    {
        return 'hotel.housekeeping.export';
    }

    public function title(array $filters): string
    {
        return __('hotel.lists.tasks');
    }

    public function resource(Model $model): JsonResource
    {
        return TaskResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'business_date' => ListSort::column('business_date'),
            'status' => ListSort::column('status'),
            'kind' => ListSort::column('kind'),
            'finished_at' => ListSort::column('finished_at'),
        ];
    }

    public function defaultSort(): string
    {
        return '-business_date';
    }

    public function columns(): array
    {
        return [
            self::day('business_date'),
            self::col('room', ['room'], fn (array $row) => $row['room']['number'] ?? null),
            self::col('floor', ['room'], fn (array $row) => $row['room']['floor'] ?? null),
            self::col('kind', ['kind'], fn (array $row) => __('hotel.task_kinds.'.$row['kind'])),
            self::col('status', ['status'], fn (array $row) => __('hotel.states.'.$row['status'])),
            self::col('assigned_to', ['assigned_to'], fn (array $row) => $row['assigned_to']['name'] ?? null),
            self::at('started_at'),
            self::at('finished_at'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
