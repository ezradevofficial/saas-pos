<?php

namespace Modules\HR\Contracts;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Company;
use Modules\HR\Models\Contract;

/**
 * HR-33 (stage 7C, Payroll): the contracts of a company's employees with
 * their pay (the `pay` group, HR-09), the same values ContractChanged
 * carries, for Payroll's copy when it is switched on after the contracts
 * exist or refreshes before a run. Read by the system inside a payroll
 * run: each read is audited (`hr.contract.payroll_read`, with the run).
 */
final class EmploymentContracts
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @return list<array<string, mixed>> the contracts of $companyId's employees (or only of $employeeIds) */
    public function forPayrollRun(string $companyId, ?string $runId, ?array $employeeIds = null): array
    {
        $contracts = Contract::query()->where('company_id', $companyId)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->orderBy('employee_id')->orderBy('start_date')->get();

        if ($contracts->isNotEmpty()) {
            $this->auditor->record('hr.contract.payroll_read', Company::query()->find($companyId), null, ['contracts' => $contracts->count(), 'run_id' => $runId]);
        }

        return $contracts->map(fn (Contract $c) => [
            'id' => $c->id,
            'employee_id' => $c->employee_id,
            'company_id' => $c->company_id,
            'type' => $c->type,
            'start_date' => $c->start_date->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'pay_basis' => $c->pay_basis,
            'pay_minor' => (string) $c->pay_minor,
            'currency' => $c->currency,
            'hours_per_week' => $c->hours_per_week === null ? null : (string) $c->hours_per_week,
            'status' => $c->status,
        ])->all();
    }
}
