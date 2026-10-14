<?php

namespace Modules\HR\Attendance;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Punch;
use Modules\HR\Support\Errors;

/**
 * HR-22: punches. Stored once per id (uploads carry the device's UUID: a
 * punch already held is answered as stored again, NFR-04); a punch dated in
 * a closed period is kept, flagged `period_closed`, and changes nothing
 * there. Every new punch recomputes its timesheet day (HR-25). Manual
 * punches and voids are audited.
 */
class Punches
{
    public function __construct(
        private readonly Timesheets $timesheets,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Store a punch; returns [punch, created].
     *
     * @param  array{id?: ?string, kind: string, occurred_at: CarbonImmutable, source: string, device_id?: ?string, attendance_device_id?: ?string, external_id?: ?string, location_id?: ?string, note?: ?string, recorded_by?: ?string}  $data
     * @return array{0: Punch, 1: bool}
     */
    public function record(Employee $employee, array $data): array
    {
        $id = $data['id'] ?? (string) Str::uuid7();

        [$punch, $created] = Errors::transaction(function () use ($employee, $data, $id) {
            $existing = Punch::query()->whereKey($id)->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            if (($data['external_id'] ?? null) !== null) {
                $byExternal = Punch::query()->where('attendance_device_id', $data['attendance_device_id'])->where('external_id', $data['external_id'])->first();

                if ($byExternal !== null) {
                    return [$byExternal, false];
                }
            }

            $punch = new Punch([
                'employee_id' => $employee->id,
                'company_id' => $employee->company_id,
                'kind' => $data['kind'],
                'occurred_at' => $data['occurred_at'],
                'source' => $data['source'],
                'device_id' => $data['device_id'] ?? null,
                'attendance_device_id' => $data['attendance_device_id'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $data['recorded_by'] ?? null,
                'flags' => [],
                'received_at' => CarbonImmutable::now(),
            ]);
            $punch->id = $id;

            // A punch id is unique across tenants: one of another tenant is refused, never confirmed.
            $inserted = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($punch) {
                try {
                    $punch->save();

                    return true;
                } catch (UniqueConstraintViolationException) {
                    return false;
                }
            });

            if (! $inserted) {
                throw Errors::make(409, 'punch_id_taken');
            }

            $date = $this->timesheets->workDateOf($punch, $employee);

            if ($this->timesheets->closedPeriod($employee->company_id, $date) !== null) {
                $punch->fill(['flags' => ['period_closed']])->save();
            }

            if ($data['source'] === 'manual') {
                $this->auditor->record('hr.punch.create', $punch, null, ['employee_id' => $employee->id, 'kind' => $punch->kind, 'occurred_at' => $punch->occurred_at->toIso8601String(), 'note' => $punch->note]);
            }

            return [$punch, true];
        });

        if ($created) {
            $this->timesheets->touch($employee->id, $this->timesheets->workDateOf($punch, $employee));
        }

        return [$punch->refresh(), $created];
    }

    public function void(Punch $punch, User $by, string $reason): Punch
    {
        $punch = Errors::transaction(function () use ($punch, $by, $reason) {
            $punch = Punch::query()->whereKey($punch->id)->lockForUpdate()->firstOrFail();

            if ($punch->voided_at !== null) {
                throw Errors::make(409, 'document_not_open');
            }

            if ($this->timesheets->closedPeriod($punch->company_id, $this->timesheets->workDateOf($punch)) !== null) {
                throw Errors::make(409, 'period_closed');
            }

            $punch->fill(['voided_at' => CarbonImmutable::now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();
            $this->auditor->record('hr.punch.void', $punch, null, ['reason' => $reason]);

            return $punch->refresh();
        });

        $this->timesheets->touch($punch->employee_id, $this->timesheets->workDateOf($punch));

        return $punch;
    }

    /** HR-24: `auto`: the opposite of the employee's last punch of that local day (an `in` first). */
    public function nextKind(Employee $employee, CarbonImmutable $at, string $timezone): string
    {
        $local = $at->setTimezone($timezone);
        $last = Punch::query()->where('employee_id', $employee->id)->whereNull('voided_at')
            ->whereBetween('occurred_at', [$local->startOfDay()->utc(), $at])->where('occurred_at', '<', $at)
            ->orderByDesc('occurred_at')->first();

        return $last?->kind === Punch::IN ? Punch::OUT : Punch::IN;
    }
}
