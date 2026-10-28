<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hotel\Housekeeping\Housekeeping;
use Modules\Hotel\Housekeeping\HousekeepingOps;
use Modules\Hotel\Http\Lists\TaskList;
use Modules\Hotel\Http\Requests\AssignTaskRequest;
use Modules\Hotel\Http\Requests\HousekeepingBoardRequest;
use Modules\Hotel\Http\Requests\HousekeepingReportRequest;
use Modules\Hotel\Http\Requests\InspectTaskRequest;
use Modules\Hotel\Http\Requests\ListTasksRequest;
use Modules\Hotel\Http\Requests\MyTasksRequest;
use Modules\Hotel\Http\Requests\StoreTaskRequest;
use Modules\Hotel\Http\Requests\UpdateTaskRequest;
use Modules\Hotel\Http\Resources\TaskResource;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HOT-28, HOT-29: the housekeeping board, tasks and a housekeeper's own list (room and status only, no guest data). */
class HousekeepingController
{
    use RespondsWithLists;

    public function __construct(private readonly Access $access, private readonly Housekeeping $housekeeping, private readonly HousekeepingOps $ops) {}

    public function board(HousekeepingBoardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->housekeeping->board($request->validated('branch_id'))]);
    }

    public function index(ListTasksRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        return $this->taskList($request, $export, 'hotel.housekeeping.view');
    }

    /** HOT-31: the housekeeping report. */
    public function report(HousekeepingReportRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        return $this->taskList($request, $export, 'hotel.report.view');
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->housekeeping->createTask(Room::query()->findOrFail($request->validated('room_id')), $request->validated('kind'), $request->validated('business_date'), $request->validated('assigned_to'), $request->validated('notes'), $request->user());

        return TaskResource::make($task->load(TaskList::RELATIONS))->response()->setStatusCode(201);
    }

    public function assign(AssignTaskRequest $request, HousekeepingTask $hotelTask): TaskResource
    {
        return $this->show($this->housekeeping->assign($hotelTask, $request->validated('assigned_to'), $request->user()));
    }

    /** HOT-29: with an `op_id` (a phone replaying its offline queue) the change runs once. */
    public function start(UpdateTaskRequest $request, HousekeepingTask $hotelTask): JsonResponse|TaskResource
    {
        return $this->ops->once($request, 'start', $hotelTask->id, fn () => $this->show($this->housekeeping->start($hotelTask, $request->user())));
    }

    public function finish(UpdateTaskRequest $request, HousekeepingTask $hotelTask): JsonResponse|TaskResource
    {
        return $this->ops->once($request, 'finish', $hotelTask->id, fn () => $this->show($this->housekeeping->finish($hotelTask, $request->user())));
    }

    public function inspect(InspectTaskRequest $request, HousekeepingTask $hotelTask): TaskResource
    {
        return $this->show($this->housekeeping->inspect($hotelTask, $request->user()));
    }

    /** HOT-29: the phone's list: the signed-in housekeeper's open tasks and the unassigned ones of their branches, with each room's status. */
    public function mine(MyTasksRequest $request): AnonymousResourceCollection
    {
        $query = $this->access->scopeBranches(HousekeepingTask::query()->with(TaskList::RELATIONS)->whereIn('status', HousekeepingTask::OPEN), $request->user(), 'hotel.housekeeping.update')
            ->where(fn ($q) => $q->where('assigned_to', $request->user()->id)->orWhereNull('assigned_to'))
            ->orderBy('business_date')->orderBy('id');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->validated('branch'));
        }

        return TaskResource::collection($query->get());
    }

    private function show(HousekeepingTask $task): TaskResource
    {
        return TaskResource::make($task->load(TaskList::RELATIONS));
    }

    private function taskList(ListTasksRequest $request, ListExport $export, string $permission): AnonymousResourceCollection|StreamedResponse
    {
        $query = $this->access->scopeBranches(HousekeepingTask::query()->with(TaskList::RELATIONS), $request->user(), $permission);

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->validated('branch'));
        }

        $status = $request->validated('status', 'open');
        $status === 'open' ? $query->whereIn('status', HousekeepingTask::OPEN) : ($status === 'all' ? null : $query->where('status', $status));

        foreach (['kind' => 'kind', 'assigned_to' => 'assigned_to', 'date' => 'business_date'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        return $this->listed($request, $query, TaskResource::class, $export);
    }
}
