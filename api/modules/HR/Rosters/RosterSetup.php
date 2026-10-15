<?php

namespace Modules\HR\Rosters;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Tenancy\Models\Branch;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Roster;
use Modules\HR\Models\RosterMember;
use Modules\HR\Models\Rotation;
use Modules\HR\Models\Shift;
use Modules\HR\Support\Access;
use Modules\HR\Support\Errors;

/**
 * HR-16, HR-17: shifts, rotations and rosters of a company. Every
 * reference is checked to be of the same company (and not archived).
 */
class RosterSetup
{
    /** @param array<string, mixed> $data validated */
    public function createShift(array $data): Shift
    {
        $this->assertShiftCode($data['company_id'], $data['code'], null);

        return Shift::create(array_intersect_key($data, array_flip(['company_id', 'code', 'name', 'start_time', 'end_time', 'break_minutes'])));
    }

    /** @param array<string, mixed> $data validated */
    public function updateShift(Shift $shift, array $data): Shift
    {
        if (isset($data['code'])) {
            $this->assertShiftCode($shift->company_id, $data['code'], $shift->id);
        }

        $shift->fill(array_intersect_key($data, array_flip(['code', 'name', 'start_time', 'end_time', 'break_minutes'])))->save();

        return $shift->refresh();
    }

    /** @param array<string, mixed> $data validated */
    public function createRotation(array $data): Rotation
    {
        $days = $this->days($data['company_id'], $data['days']);

        return Rotation::create(['company_id' => $data['company_id'], 'name' => $data['name'], 'cycle_days' => count($days), 'days' => $days]);
    }

    /** @param array<string, mixed> $data validated */
    public function updateRotation(Rotation $rotation, array $data): Rotation
    {
        $fields = array_intersect_key($data, array_flip(['name']));

        if (array_key_exists('days', $data)) {
            $fields['days'] = $this->days($rotation->company_id, $data['days']);
            $fields['cycle_days'] = count($fields['days']);
        }

        $rotation->fill($fields)->save();

        return $rotation->refresh();
    }

    /** @param array<string, mixed> $data validated */
    public function createRoster(array $data, User $by): Roster
    {
        $this->assertRosterRefs($data['company_id'], $data);

        return Errors::transaction(function () use ($data, $by) {
            $roster = Roster::create(array_intersect_key($data, array_flip(['company_id', 'branch_id', 'department_id', 'name', 'rotation_id', 'anchor_date'])));

            if (isset($data['members'])) {
                $this->putMembers($roster, $data['members'], $by);
            }

            return $roster->refresh();
        });
    }

    /** @param array<string, mixed> $data validated */
    public function updateRoster(Roster $roster, array $data): Roster
    {
        $this->assertRosterRefs($roster->company_id, $data);
        $roster->fill(array_intersect_key($data, array_flip(['branch_id', 'department_id', 'name', 'rotation_id', 'anchor_date'])))->save();

        return $roster->refresh();
    }

    /**
     * Replace a roster's members (each an active employee of its company).
     *
     * @param  list<array{employee_id: string, offset_days?: int, from_date?: ?string, to_date?: ?string}>  $members
     */
    public function putMembers(Roster $roster, array $members, User $by): void
    {
        $ids = array_column($members, 'employee_id');
        $valid = Employee::query()->whereKey($ids)->where('company_id', $roster->company_id)->where('status', Employee::ACTIVE)->get()->keyBy('id');

        foreach ($members as $index => $member) {
            $employee = $valid->get($member['employee_id']);

            if ($employee === null) {
                throw Errors::make(422, 'employee_other_company', field: "members.{$index}.employee_id");
            }

            // HR-38: a member from outside the roster's branch needs hr.roster.manage at their own place.
            if ($roster->branch_id !== null && $employee->branch_id !== $roster->branch_id && ! app(Access::class)->can($by, 'hr.roster.manage', $employee)) {
                throw Errors::make(403, 'place_forbidden', field: "members.{$index}.employee_id");
            }
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw Errors::make(422, 'member_twice', field: 'members');
        }

        Errors::transaction(function () use ($roster, $members, $ids) {
            $before = RosterMember::query()->where('roster_id', $roster->id)->orderBy('employee_id')->get(['employee_id', 'offset_days', 'from_date', 'to_date'])->toArray();
            RosterMember::query()->where('roster_id', $roster->id)->whereNotIn('employee_id', $ids)->delete();

            foreach ($members as $member) {
                RosterMember::query()->updateOrCreate(
                    ['roster_id' => $roster->id, 'employee_id' => $member['employee_id']],
                    ['offset_days' => (int) ($member['offset_days'] ?? 0), 'from_date' => $member['from_date'] ?? null, 'to_date' => $member['to_date'] ?? null],
                );
            }

            app(Auditor::class)->record('hr.roster.members_update', $roster, ['members' => $before], ['members' => $members]);
        });
    }

    /** @param list<?string> $days shift ids (null: rest) */
    private function days(string $companyId, array $days): array
    {
        $ids = array_values(array_unique(array_filter($days)));
        $found = Shift::query()->whereKey($ids)->where('company_id', $companyId)->whereNull('archived_at')->pluck('id')->all();

        foreach ($days as $index => $id) {
            if ($id !== null && ! in_array($id, $found, true)) {
                throw Errors::make(422, 'shift_other_company', field: "days.{$index}");
            }
        }

        return array_values($days);
    }

    /** @param array<string, mixed> $data */
    private function assertRosterRefs(string $companyId, array $data): void
    {
        if (($data['branch_id'] ?? null) !== null && ! Branch::query()->whereKey($data['branch_id'])->where('company_id', $companyId)->exists()) {
            throw Errors::make(422, 'place_other_company', field: 'branch_id');
        }

        if (($data['department_id'] ?? null) !== null && ! Department::query()->whereKey($data['department_id'])->where('company_id', $companyId)->exists()) {
            throw Errors::make(422, 'department_other_company', field: 'department_id');
        }

        if (isset($data['rotation_id']) && ! Rotation::query()->whereKey($data['rotation_id'])->where('company_id', $companyId)->whereNull('archived_at')->exists()) {
            throw Errors::make(422, 'rotation_other_company', field: 'rotation_id');
        }
    }

    private function assertShiftCode(string $companyId, string $code, ?string $exceptId): void
    {
        $taken = Shift::query()->where('company_id', $companyId)->whereNull('archived_at')->whereRaw('lower(code) = lower(?)', [$code])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();

        if ($taken) {
            throw Errors::make(422, 'code_taken', field: 'code');
        }
    }
}
