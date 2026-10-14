<?php

namespace Modules\HR\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\LeaveRequestResource;

/** HR-14, HR-35: leave requests, newest first. */
class LeaveRequestList extends HrList
{
    public const RELATIONS = ['employee', 'leaveType', 'requester'];

    public function name(): string
    {
        return 'hr-leave-requests';
    }

    public function auditAction(): string
    {
        return 'hr.leave_request.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.leave_requests');
    }

    public function resource(Model $model): JsonResource
    {
        return LeaveRequestResource::make($model);
    }

    public function sorts(): array
    {
        return ['created_at' => ListSort::column('created_at'), 'start_date' => ListSort::column('start_date'), 'number' => ListSort::column('number'), 'status' => ListSort::column('status')];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::employee(),
            ListColumn::make('leave_type', 'hr.columns.leave_type', ['leave_type'], fn (array $row) => $row['leave_type']['name'] ?? null),
            self::date('start_date'),
            self::date('end_date'),
            self::text('days'),
            self::status(),
            self::text('reason'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
