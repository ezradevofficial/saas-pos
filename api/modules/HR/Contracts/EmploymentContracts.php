<?php

namespace Modules\HR\Contracts;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use DateTimeInterface;
use InvalidArgumentException;
use Modules\HR\Models\Contract;

/**
 * HR-33 (stage 7C, Payroll): the contracts of a company's employees with
 * their pay (the `pay` group, HR-09), the same values ContractChanged
 * carries, for Payroll's copy. Read either by the system inside a payroll
 * run (its id) or for a user who holds `hr.pay.view` at the company;
 * nothing else. Every read is audited (`hr.contract.payroll_read`), even
 * an empty one.
 */
final class EmploymentContracts
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly ScopeResolver $resolver,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forPayrollRun(string $companyId, string $runId, ?array $employeeIds = null, ?DateTimeInterface $since = null): array
    {
        return $this->read($companyId, ['context' => 'payroll_run', 'run_id' => $runId], $employeeIds, $since);
    }

    /** @return list<array<string, mixed>> for $user, who must hold `hr.pay.view` at the company (403 otherwise) */
    public function forUser(string $companyId, User $user, ?array $employeeIds = null, ?DateTimeInterface $since = null): array
    {
        if (! $this->resolver->can($user, 'hr.pay.view', Scope::company($companyId))) {
            throw new ApiException(403, 'forbidden', __('hr.errors.place_forbidden'));
        }

        return $this->read($companyId, ['context' => 'user'], $employeeIds, $since);
    }

    /**
     * With $since, only the contracts changed at or after it (stage 7C
     * closing review: Payroll reads incrementally); each carries its
     * `version`, so a late copy never overwrites a newer one.
     *
     * @return list<array<string, mixed>>
     */
    private function read(string $companyId, array $context, ?array $employeeIds, ?DateTimeInterface $since): array
    {
        if ($companyId === '') {
            throw new InvalidArgumentException('A company is needed.');
        }

        $contracts = Contract::query()->where('company_id', $companyId)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('employee_id')->orderBy('start_date')->get();

        $this->auditor->record('hr.contract.payroll_read', Company::query()->find($companyId), null, ['contracts' => $contracts->count(), ...$context]);

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
            'version' => (int) $c->version,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ])->all();
    }
}
