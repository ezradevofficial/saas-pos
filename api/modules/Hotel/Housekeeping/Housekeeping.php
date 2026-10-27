<?php

namespace Modules\Hotel\Housekeeping;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Properties;

/**
 * HOT-05, HOT-28, HOT-29: room status and housekeeping tasks.
 *
 * - A room is `vacant_clean`, `vacant_dirty`, `occupied` or `out_of_order`.
 *   Check-in occupies it, check-out makes it dirty with a clean task,
 *   finishing a clean makes it clean. Housekeepers change only between
 *   dirty and clean (a room with a guest or out of order is not theirs to
 *   change); out of order is the room manager's, with a reason.
 * - A task is one job on a room for a business date: pending, in progress,
 *   done, inspected or cancelled. Created tasks are never doubled (one open
 *   task of a kind per room and date).
 */
class Housekeeping
{
    public function __construct(
        private readonly Properties $properties,
        private readonly Auditor $auditor,
    ) {}

    /** The clean task a checked-out room needs (once per room and date). */
    public function ensureTask(Room $room, string $kind, ?string $date = null, ?User $by = null, ?string $notes = null, ?string $assignedTo = null): HousekeepingTask
    {
        $date ??= $this->properties->businessDate($room->branch_id);
        $id = (string) Str::uuid7();

        $inserted = HousekeepingTask::query()->insertOrIgnore([
            'id' => $id, 'tenant_id' => app(TenantContext::class)->require(), 'company_id' => $room->company_id, 'branch_id' => $room->branch_id,
            'room_id' => $room->id, 'kind' => $kind, 'status' => HousekeepingTask::PENDING, 'business_date' => $date, 'assigned_to' => $assignedTo,
            'notes' => $notes, 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $inserted > 0
            ? HousekeepingTask::query()->findOrFail($id)
            : HousekeepingTask::query()->where('room_id', $room->id)->where('kind', $kind)->where('business_date', $date)->where('status', '<>', HousekeepingTask::CANCELLED)->firstOrFail();
    }

    /** HOT-28: a task made by hand. */
    public function createTask(Room $room, string $kind, ?string $date, ?string $assignedTo, ?string $notes, User $by): HousekeepingTask
    {
        $task = $this->ensureTask($room, $kind, $date, $by, $notes, $assignedTo);
        $this->auditor->record('hotel.housekeeping.task_create', $task, null, ['room_id' => $room->id, 'kind' => $kind, 'business_date' => $task->business_date->toDateString()]);

        return $task;
    }

    public function assign(HousekeepingTask $task, ?string $userId, User $by): HousekeepingTask
    {
        $this->assertOpen($task);
        $task->forceFill(['assigned_to' => $userId])->save();
        $this->auditor->record('hotel.housekeeping.assign', $task, null, ['assigned_to' => $userId]);

        return $task->refresh();
    }

    public function start(HousekeepingTask $task, User $by): HousekeepingTask
    {
        if ($task->status !== HousekeepingTask::PENDING) {
            throw HotelError::make(409, 'task_not_pending');
        }

        $task->forceFill(['status' => HousekeepingTask::IN_PROGRESS, 'started_at' => now(), 'assigned_to' => $task->assigned_to ?? $by->id])->save();
        $this->auditor->record('hotel.housekeeping.start', $task, null, ['room_id' => $task->room_id]);

        return $task->refresh();
    }

    /** Finish a task: a checkout or deep clean makes a dirty room clean. */
    public function finish(HousekeepingTask $task, User $by): HousekeepingTask
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($task, $by) {
            $task = HousekeepingTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($task);
            $task->forceFill(['status' => HousekeepingTask::DONE, 'finished_at' => now(), 'started_at' => $task->started_at ?? now(), 'assigned_to' => $task->assigned_to ?? $by->id])->save();
            $room = Room::query()->whereKey($task->room_id)->lockForUpdate()->firstOrFail();

            if (in_array($task->kind, ['checkout_clean', 'deep_clean'], true) && $room->status === Room::VACANT_DIRTY) {
                $this->setStatus($room, Room::VACANT_CLEAN, $by);
            }

            $this->auditor->record('hotel.housekeeping.finish', $task, null, ['room_id' => $room->id, 'room_status' => $room->refresh()->status]);

            return $task->refresh();
        });
    }

    public function inspect(HousekeepingTask $task, User $by): HousekeepingTask
    {
        if ($task->status !== HousekeepingTask::DONE) {
            throw HotelError::make(409, 'task_not_done');
        }

        $task->forceFill(['status' => HousekeepingTask::INSPECTED])->save();
        $this->auditor->record('hotel.housekeeping.inspect', $task, null, ['room_id' => $task->room_id, 'inspected_by' => $by->id]);

        return $task->refresh();
    }

    /**
     * HOT-05, HOT-29: change a room's status. `$byHousekeeper` limits it to
     * dirty ⇄ clean; `out_of_order` needs a reason (dates optional).
     *
     * @param  array{reason?: ?string, from?: ?string, until?: ?string}  $ooo
     */
    public function setStatus(Room $room, string $status, User $by, array $ooo = [], bool $byHousekeeper = false): Room
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($room, $status, $ooo, $byHousekeeper) {
            $room = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($room->archived_at !== null) {
                throw HotelError::make(409, 'room_archived');
            }

            if ($status === $room->status && $status !== Room::OUT_OF_ORDER) {
                return $room;
            }

            if ($byHousekeeper && (! in_array($status, [Room::VACANT_CLEAN, Room::VACANT_DIRTY], true) || ! in_array($room->status, [Room::VACANT_CLEAN, Room::VACANT_DIRTY], true))) {
                throw HotelError::make(409, 'room_status_not_allowed');
            }

            if ($status === Room::OCCUPIED && ! $this->hasGuest($room)) {
                throw HotelError::make(409, 'room_status_not_allowed');
            }

            if ($room->status === Room::OCCUPIED && $status !== Room::OCCUPIED && $this->hasGuest($room)) {
                throw HotelError::make(409, 'room_has_guest');
            }

            if ($status === Room::OUT_OF_ORDER && trim((string) ($ooo['reason'] ?? '')) === '') {
                throw HotelError::make(422, 'ooo_reason_required', field: 'reason');
            }

            $room->forceFill([
                'status' => $status,
                'status_changed_at' => now(),
                'ooo_reason' => $status === Room::OUT_OF_ORDER ? $ooo['reason'] : null,
                'ooo_from' => $status === Room::OUT_OF_ORDER ? ($ooo['from'] ?? null) : null,
                'ooo_until' => $status === Room::OUT_OF_ORDER ? ($ooo['until'] ?? null) : null,
            ])->save();

            return $room->refresh();
        });
    }

    /** The board of a property (HOT-28): rooms by floor with their open task and the stay around them. */
    public function board(string $branchId): array
    {
        $today = $this->properties->businessDate($branchId);
        $rooms = Room::query()->where('branch_id', $branchId)->whereNull('archived_at')->with('roomType:id,code,name')->orderBy('floor')->orderBy('number')->get();
        $tasks = HousekeepingTask::query()->where('branch_id', $branchId)->whereIn('status', HousekeepingTask::OPEN)->with('assignee:id,name')->get()->groupBy('room_id');
        $stays = Stay::query()->where('branch_id', $branchId)->whereNotNull('room_id')->whereIn('status', Stay::HOLDING)
            ->where('arrival_on', '<=', $today)->where('departure_on', '>=', $today)->get(['id', 'room_id', 'status', 'arrival_on', 'departure_on'])->groupBy('room_id');

        return [
            'business_date' => $today,
            'counts' => $rooms->countBy('status')->all(),
            'rooms' => $rooms->map(fn (Room $room) => [
                'id' => $room->id, 'number' => $room->number, 'floor' => $room->floor, 'status' => $room->status, 'features' => $room->features,
                'room_type' => ['id' => $room->roomType?->id, 'code' => $room->roomType?->code, 'name' => $room->roomType?->name],
                'out_of_order' => $room->status === Room::OUT_OF_ORDER ? ['reason' => $room->ooo_reason, 'from' => $room->ooo_from?->toDateString(), 'until' => $room->ooo_until?->toDateString()] : null,
                'departing_today' => $stays->get($room->id, collect())->contains(fn (Stay $s) => $s->status === Stay::CHECKED_IN && $s->departure_on->toDateString() <= $today),
                'arriving_today' => $stays->get($room->id, collect())->contains(fn (Stay $s) => $s->status === Stay::RESERVED && $s->arrival_on->toDateString() === $today),
                'tasks' => $tasks->get($room->id, collect())->map(fn (HousekeepingTask $t) => [
                    'id' => $t->id, 'kind' => $t->kind, 'status' => $t->status, 'business_date' => $t->business_date->toDateString(),
                    'assigned_to' => $t->assignee === null ? null : ['id' => $t->assignee->id, 'name' => $t->assignee->name],
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function hasGuest(Room $room): bool
    {
        return Stay::query()->where('room_id', $room->id)->where('status', Stay::CHECKED_IN)->exists();
    }

    private function assertOpen(HousekeepingTask $task): void
    {
        if (! in_array($task->status, HousekeepingTask::OPEN, true)) {
            throw HotelError::make(409, 'task_closed');
        }
    }
}
