<?php

namespace Modules\HR\Contracts;

use Modules\HR\Models\Employee;
use Modules\HR\Models\PayAccount;
use Modules\HR\Support\Sensitive;

/**
 * HR-33: an employee's active pay accounts, decrypted, for Payroll's
 * payment files: `forUser()` (hr.bank.view) or `forPayrollRun()`.
 */
final class PayAccounts extends SensitiveReads
{
    protected function group(): string
    {
        return Sensitive::BANK;
    }

    /** @return list<array{id: string, kind: string, provider: string, branch_code: ?string, account_name: string, account_number: string, currency: string, is_primary: bool}> */
    protected function read(Employee $employee): array
    {
        return $this->readMany([$employee])[$employee->id];
    }

    /** One query for every employee's accounts (payment files, decision 38). */
    protected function readMany(array $employees): array
    {
        $ids = array_map(fn (Employee $e) => $e->id, $employees);
        $accounts = $ids === [] ? collect() : PayAccount::query()->whereIn('employee_id', $ids)->whereNull('archived_at')
            ->orderByDesc('is_primary')->orderBy('created_at')->get()->groupBy('employee_id');

        return array_combine($ids, array_map(fn (string $id) => collect($accounts[$id] ?? [])->map(fn (PayAccount $a) => [
            'id' => $a->id, 'kind' => $a->kind, 'provider' => $a->provider, 'branch_code' => $a->branch_code,
            'account_name' => $a->account_name, 'account_number' => $a->account_number, 'currency' => $a->currency, 'is_primary' => $a->is_primary,
        ])->values()->all(), $ids));
    }
}
