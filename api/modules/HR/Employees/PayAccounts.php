<?php

namespace Modules\HR\Employees;

use Modules\HR\Models\Employee;
use Modules\HR\Models\PayAccount;
use Modules\HR\Support\Errors;
use Modules\HR\Support\Sensitive;

/**
 * HR-04: pay accounts. One primary active account per employee (the first
 * one is primary); numbers encrypted (the model's cast) and audited by
 * hint only (HR-11).
 */
class PayAccounts
{
    /** @param array<string, mixed> $data validated */
    public function create(Employee $employee, array $data): PayAccount
    {
        return Errors::transaction(function () use ($employee, $data) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $first = ! PayAccount::query()->where('employee_id', $employee->id)->whereNull('archived_at')->exists();
            $primary = $first || (bool) ($data['is_primary'] ?? false);

            if ($primary) {
                $this->clearPrimary($employee->id);
            }

            return PayAccount::create([
                'employee_id' => $employee->id,
                'kind' => $data['kind'],
                'provider' => $data['provider'],
                'branch_code' => $data['branch_code'] ?? null,
                'account_name' => $data['account_name'],
                'account_number' => $data['account_number'],
                'account_hint' => Sensitive::hintOf($data['account_number']),
                'currency' => strtoupper($data['currency']),
                'is_primary' => $primary,
            ]);
        });
    }

    /** @param array<string, mixed> $data validated (only the keys sent) */
    public function update(PayAccount $account, array $data): PayAccount
    {
        return Errors::transaction(function () use ($account, $data) {
            $account = PayAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($account->isArchived()) {
                throw Errors::make(409, 'document_not_open');
            }

            if (($data['is_primary'] ?? false) === true && ! $account->is_primary) {
                $this->clearPrimary($account->employee_id);
            }

            $fields = array_intersect_key($data, array_flip(['kind', 'provider', 'branch_code', 'account_name', 'currency', 'is_primary']));

            if (isset($fields['currency'])) {
                $fields['currency'] = strtoupper($fields['currency']);
            }

            if (array_key_exists('account_number', $data)) {
                $fields['account_number'] = $data['account_number'];
                $fields['account_hint'] = Sensitive::hintOf($data['account_number']);
            }

            // The primary flag only moves to another account, never off the last one.
            if (($fields['is_primary'] ?? null) === false && $account->is_primary) {
                unset($fields['is_primary']);
            }

            $account->fill($fields)->save();

            return $account->refresh();
        });
    }

    public function archive(PayAccount $account): PayAccount
    {
        return Errors::transaction(function () use ($account) {
            $account = PayAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($account->isArchived()) {
                return $account;
            }

            $wasPrimary = $account->is_primary;
            $account->fill(['is_primary' => false])->archive();

            if ($wasPrimary) {
                $next = PayAccount::query()->where('employee_id', $account->employee_id)->whereNull('archived_at')->orderBy('created_at')->first();
                $next?->fill(['is_primary' => true])->save();
            }

            return $account->refresh();
        });
    }

    private function clearPrimary(string $employeeId): void
    {
        foreach (PayAccount::query()->where('employee_id', $employeeId)->where('is_primary', true)->whereNull('archived_at')->get() as $account) {
            $account->fill(['is_primary' => false])->save();
        }
    }
}
