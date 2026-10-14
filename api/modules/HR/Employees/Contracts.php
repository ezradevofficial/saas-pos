<?php

namespace Modules\HR\Employees;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\HR\Events\ContractChanged;
use Modules\HR\Models\Contract;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Position;
use Modules\HR\Support\Errors;

/**
 * HR-07: contracts. At most one in force per employee on any date; pay in
 * minor units of its currency (ADR 003), audited as `pay_changed` only
 * (HR-11). Every change raises ContractChanged (HR-33).
 */
class Contracts
{
    public function __construct(
        private readonly CurrencyDecimals $decimals,
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data validated */
    public function create(Employee $employee, array $data, User $by): Contract
    {
        if (! $employee->isActive()) {
            throw Errors::make(409, 'employee_terminated');
        }

        $fields = $this->fields($employee, $data, null);

        return Errors::transaction(function () use ($employee, $fields, $by) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->assertNoOverlap($employee->id, $fields['start_date'], $fields['end_date'] ?? null, null);

            $contract = Contract::create([...$fields, 'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'status' => Contract::ACTIVE, 'created_by' => $by->id]);
            $this->auditor->record('hr.contract.pay_set', $contract, null, ['pay_changed' => true]);
            $this->changed($contract, ContractChanged::CREATED);

            return $contract->refresh();
        });
    }

    /** @param array<string, mixed> $data validated (only the keys sent) */
    public function update(Contract $contract, array $data): Contract
    {
        $employee = Employee::query()->findOrFail($contract->employee_id);
        $fields = $this->fields($employee, $data, $contract);

        return Errors::transaction(function () use ($contract, $fields) {
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($contract->status !== Contract::ACTIVE) {
                throw Errors::make(409, 'document_not_open');
            }

            $contract->fill($fields);
            $this->assertNoOverlap($contract->employee_id, $contract->start_date->toDateString(), $contract->end_date?->toDateString(), $contract->id);
            $payChanged = $contract->isDirty('pay_minor') || $contract->isDirty('currency') || $contract->isDirty('pay_basis');
            $contract->save();

            if ($payChanged) {
                $this->auditor->record('hr.contract.pay_set', $contract, null, ['pay_changed' => true]);
            }

            $this->changed($contract, ContractChanged::UPDATED);

            return $contract->refresh();
        });
    }

    public function end(Contract $contract, string $endDate, ?string $reason): Contract
    {
        return Errors::transaction(function () use ($contract, $endDate, $reason) {
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($contract->status !== Contract::ACTIVE) {
                throw Errors::make(409, 'document_not_open');
            }

            if (CarbonImmutable::parse($endDate)->lessThan($contract->start_date)) {
                throw Errors::make(422, 'contract_end_before_start', field: 'end_date');
            }

            $contract->fill(['end_date' => $endDate, 'status' => Contract::ENDED, 'end_reason' => $reason])->save();
            $this->changed($contract, ContractChanged::ENDED);

            return $contract->refresh();
        });
    }

    /** HR-05: termination ends the contract in force on its date (or keeps an earlier end). */
    public function endOnTermination(Contract $contract, CarbonImmutable $date, User $by): void
    {
        $end = $contract->end_date !== null && $contract->end_date->lessThan($date) ? $contract->end_date : $date;
        $end = $end->lessThan($contract->start_date) ? $contract->start_date : $end;

        $this->end($contract, $end->toDateString(), __('hr.contracts.ended_on_termination'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(Employee $employee, array $data, ?Contract $current): array
    {
        $fields = array_intersect_key($data, array_flip(['type', 'start_date', 'end_date', 'probation_end_date', 'position_id', 'department_id', 'pay_basis', 'hours_per_week']));

        if (array_key_exists('pay_amount', $data) || array_key_exists('currency', $data)) {
            $currency = strtoupper((string) ($data['currency'] ?? $current?->currency));
            $amount = (string) ($data['pay_amount'] ?? ($current === null ? '0' : Money::ofMinor($current->pay_minor, $current->currency)->toDecimalString($this->decimals)));

            try {
                $fields['pay_minor'] = (int) Money::parse($amount, $currency, $this->decimals)->minor();
            } catch (InvalidArgumentException) {
                throw Errors::make(422, 'pay_precision', ['currency' => $currency], 'pay_amount');
            }

            $fields['currency'] = $currency;
        }

        if (($fields['position_id'] ?? null) !== null && ! Position::query()->whereKey($fields['position_id'])->where('company_id', $employee->company_id)->exists()) {
            throw Errors::make(422, 'position_other_company', field: 'position_id');
        }

        if (($fields['department_id'] ?? null) !== null && ! Department::query()->whereKey($fields['department_id'])->where('company_id', $employee->company_id)->exists()) {
            throw Errors::make(422, 'department_other_company', field: 'department_id');
        }

        $type = $fields['type'] ?? $current?->type;
        $end = array_key_exists('end_date', $fields) ? $fields['end_date'] : $current?->end_date?->toDateString();

        if ($type === 'fixed_term' && $end === null) {
            throw Errors::make(422, 'contract_end_required', field: 'end_date');
        }

        return $fields;
    }

    private function assertNoOverlap(string $employeeId, string $start, ?string $end, ?string $exceptId): void
    {
        $overlap = Contract::query()->where('employee_id', $employeeId)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $start))
            ->when($end !== null, fn ($q) => $q->where('start_date', '<=', $end))
            ->exists();

        if ($overlap) {
            throw Errors::make(422, 'contract_overlap', field: 'start_date');
        }
    }

    private function changed(Contract $contract, string $change): void
    {
        ContractChanged::dispatch(
            $this->tenants->require(), $contract->id, $contract->employee_id, $contract->company_id, $change, $contract->type,
            $contract->start_date->toDateString(), $contract->end_date?->toDateString(), $contract->probation_end_date?->toDateString(),
            $contract->pay_basis, (string) $contract->pay_minor, $contract->currency,
            $contract->hours_per_week === null ? null : (string) $contract->hours_per_week, $contract->position_id, $contract->department_id,
        );
    }
}
