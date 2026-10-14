<?php

namespace Modules\HR\Contracts;

use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Models\PayAccount;

/**
 * HR-33: what Payroll reads from HR besides its events, decrypted: an
 * employee's active pay accounts (the payment file) and identifiers
 * (statutory returns). The caller (Payroll, inside a run it authorised)
 * holds the `hr.bank.view` / `hr.identity.view` decision; every read is
 * audited by the caller's run, never returned through the API.
 */
class HrRecords
{
    /** @return list<array{id: string, kind: string, provider: string, branch_code: ?string, account_name: string, account_number: string, currency: string, is_primary: bool}> */
    public function payAccounts(string $employeeId): array
    {
        return PayAccount::query()->where('employee_id', $employeeId)->whereNull('archived_at')->orderByDesc('is_primary')->orderBy('created_at')->get()
            ->map(fn (PayAccount $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'provider' => $a->provider, 'branch_code' => $a->branch_code,
                'account_name' => $a->account_name, 'account_number' => $a->account_number, 'currency' => $a->currency, 'is_primary' => $a->is_primary,
            ])->all();
    }

    /** @return array<string, string> type => value */
    public function identifiers(string $employeeId): array
    {
        return EmployeeIdentifier::query()->where('employee_id', $employeeId)->get()->mapWithKeys(fn (EmployeeIdentifier $i) => [$i->type => $i->value])->all();
    }
}
