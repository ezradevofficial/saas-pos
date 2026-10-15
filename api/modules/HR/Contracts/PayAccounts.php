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
        return PayAccount::query()->where('employee_id', $employee->id)->whereNull('archived_at')->orderByDesc('is_primary')->orderBy('created_at')->get()
            ->map(fn (PayAccount $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'provider' => $a->provider, 'branch_code' => $a->branch_code,
                'account_name' => $a->account_name, 'account_number' => $a->account_number, 'currency' => $a->currency, 'is_primary' => $a->is_primary,
            ])->all();
    }
}
