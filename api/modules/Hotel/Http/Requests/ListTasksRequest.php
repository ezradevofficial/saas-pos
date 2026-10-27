<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;
use Modules\Hotel\Http\Lists\TaskList;
use Modules\Hotel\Models\HousekeepingTask;

/**
 * GET hotel/housekeeping/tasks (HOT-28, HOT-31): `hotel.housekeeping.view` at the reader's branches. `?branch=`, `?status=`, `?kind=`, `?assigned_to=`,
 * `?date=`, `?sort=`, pages and an export (the housekeeping report).
 */
class ListTasksRequest extends HotelRequest
{
    use SortsAndExports;

    protected function permission(): string
    {
        return 'hotel.housekeeping.view';
    }

    public function list(): TaskList
    {
        return new TaskList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'status' => ['sometimes', 'string', Rule::in([...array_diff([HousekeepingTask::PENDING, HousekeepingTask::IN_PROGRESS, HousekeepingTask::DONE, HousekeepingTask::INSPECTED, HousekeepingTask::CANCELLED], []), 'open', 'all'])],
            'kind' => ['sometimes', 'string', Rule::in(HousekeepingTask::KINDS)],
            'assigned_to' => ['sometimes', 'uuid', Rule::exists('users', 'id')],
            'date' => HotelRules::day(false),
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
