<?php

namespace Modules\HR\Records;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\HR\Models\DisciplinaryCase;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Errors;
use Modules\HR\Support\Numbers;

/** HR-29: disciplinary cases; texts audited by name only (HR-11). */
class DisciplinaryCases
{
    public const TEXTS = ['description', 'action_taken', 'outcome'];

    public function __construct(
        private readonly Numbers $numbers,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data validated */
    public function open(Employee $employee, array $data, User $by): DisciplinaryCase
    {
        return Errors::transaction(function () use ($employee, $data, $by) {
            $case = DisciplinaryCase::create([
                'number' => $this->numbers->next(Numbers::DISCIPLINARY_CASE, $employee->company_id, $employee->branch_id),
                'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'category' => $data['category'],
                'incident_date' => $data['incident_date'], 'description' => $data['description'], 'action_taken' => $data['action_taken'] ?? null,
                'status' => 'open', 'opened_by' => $by->id,
            ]);
            $this->auditor->record('hr.disciplinary_case.text_update', $case, null, ['fields' => array_values(array_filter(self::TEXTS, fn ($f) => $case->{$f} !== null))]);

            return $case->refresh();
        });
    }

    /** @param array<string, mixed> $data validated */
    public function update(DisciplinaryCase $case, array $data): DisciplinaryCase
    {
        return Errors::transaction(function () use ($case, $data) {
            $case = DisciplinaryCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($case->status !== 'open') {
                throw Errors::make(409, 'document_not_open');
            }

            $case->fill(array_intersect_key($data, array_flip(['category', 'incident_date', 'description', 'action_taken'])));
            $texts = array_values(array_filter(self::TEXTS, fn ($f) => $case->isDirty($f)));
            $case->save();

            if ($texts !== []) {
                $this->auditor->record('hr.disciplinary_case.text_update', $case, null, ['fields' => $texts]);
            }

            return $case->refresh();
        });
    }

    public function close(DisciplinaryCase $case, string $outcome, User $by): DisciplinaryCase
    {
        return Errors::transaction(function () use ($case, $outcome, $by) {
            $case = DisciplinaryCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($case->status !== 'open') {
                throw Errors::make(409, 'document_not_open');
            }

            $case->fill(['status' => 'closed', 'outcome' => $outcome, 'closed_by' => $by->id, 'closed_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('hr.disciplinary_case.text_update', $case, null, ['fields' => ['outcome']]);

            return $case->refresh();
        });
    }
}
