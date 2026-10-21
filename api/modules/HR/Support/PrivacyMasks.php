<?php

namespace Modules\HR\Support;

use App\Core\Identity\Models\User;
use Closure;
use Modules\HR\Models\Employee;

/**
 * NFR-08, HR-09, HR-11, RBAC-05 (review H2): an employee's rows in a data
 * subject access report, as the person downloading it may see them. A
 * sensitive group (identity, bank, pay, medical, disciplinary) the
 * downloader cannot see at the employee's place, or whose fields their
 * field rules hide, is masked (`[hidden]`); personal fields hidden by field
 * rules too. Groups shown in full are audited as `hr.employee.sensitive_view`
 * (context `privacy_access_report`), once per employee.
 *
 * Encrypted values (ID numbers, account numbers, medical notes) never enter
 * a report at all (ExportColumns::DENIED); this covers what is left: hints,
 * dates of birth, account names, contracts, disciplinary cases, documents
 * and pay records.
 */
class PrivacyMasks
{
    public const HIDDEN = '[hidden]';

    /** Columns of a table that belong to a group (the rest of the row stays). */
    public const GROUP_FIELDS = [
        'hr_employees' => [Sensitive::IDENTITY => ['date_of_birth']],
        'hr_employee_identifiers' => [Sensitive::IDENTITY => ['value_hint']],
        'hr_pay_accounts' => [Sensitive::BANK => ['account_hint', 'account_name', 'branch_code', 'provider']],
    ];

    /** Tables whose whole rows belong to a group. */
    public const GROUP_ROWS = [
        'hr_contracts' => Sensitive::PAY,
        'hr_disciplinary_cases' => Sensitive::DISCIPLINARY,
    ];

    /** Columns every masked row keeps, so the reader still sees that a record exists. */
    private const KEEP = ['id', 'tenant_id', 'employee_id', 'payroll_employee_id', 'created_at'];

    public function __construct(private readonly Sensitive $sensitive) {}

    /** @return Closure(list<array<string, mixed>>, User): list<array<string, mixed>> */
    public function forTable(string $table): Closure
    {
        return fn (array $rows, User $downloader) => $this->mask($table, $rows, $downloader, fn (array $row) => $table === 'hr_employees' ? $row['id'] : ($row['employee_id'] ?? null));
    }

    /**
     * Rows that belong wholly to $group (pay records of Payroll, for one), with
     * the employee each row is about.
     *
     * @param  Closure(array<string, mixed>): ?string  $employeeOf
     * @return Closure(list<array<string, mixed>>, User): list<array<string, mixed>>
     */
    public function forGroupRows(string $group, Closure $employeeOf): Closure
    {
        return fn (array $rows, User $downloader) => array_map(function (array $row) use ($group, $employeeOf, $downloader) {
            $employee = $this->employee($employeeOf($row));

            return $employee !== null && $this->shown($downloader, $employee, $group) ? $row : $this->hideRow($row, $group);
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function mask(string $table, array $rows, User $downloader, Closure $employeeOf): array
    {
        return array_map(function (array $row) use ($table, $downloader, $employeeOf) {
            $employee = $this->employee($employeeOf($row));
            if ($employee === null) {
                return $row;
            }

            if (isset(self::GROUP_ROWS[$table])) {
                return $this->shown($downloader, $employee, self::GROUP_ROWS[$table]) ? $row : $this->hideRow($row, self::GROUP_ROWS[$table]);
            }

            if ($table === 'hr_documents') {
                $group = Sensitive::DOCUMENT_GROUPS[$row['category'] ?? ''] ?? null;

                return $group === null || $this->shown($downloader, $employee, $group) ? $row : $this->hideRow($row, $group);
            }

            foreach (self::GROUP_FIELDS[$table] ?? [] as $group => $fields) {
                $present = array_filter($fields, fn (string $field) => array_key_exists($field, $row) && $row[$field] !== null);
                if ($present === []) {
                    continue;
                }
                if (! $this->shown($downloader, $employee, $group)) {
                    foreach ($present as $field) {
                        $row[$field] = self::HIDDEN;
                    }
                }
            }

            if ($table === 'hr_employees') {
                foreach (Sensitive::PERSONAL_FIELDS as $field) {
                    if (array_key_exists($field, $row) && $this->sensitive->hides($downloader, $field)) {
                        $row[$field] = self::HIDDEN;
                    }
                }
            }

            return $row;
        }, $rows);
    }

    /** Whether $downloader sees $group of $employee in full; audited when so. */
    private function shown(User $downloader, Employee $employee, string $group): bool
    {
        if (! $this->sensitive->sees($downloader, $employee, $group)) {
            return false;
        }

        $this->sensitive->recordView($employee, [$group], 'privacy_access_report');

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function hideRow(array $row, string $group): array
    {
        $kept = array_intersect_key($row, array_flip(self::KEEP));

        return [...$kept, 'hidden' => $group];
    }

    private function employee(?string $id): ?Employee
    {
        return $id === null ? null : Employee::query()->find($id);
    }
}
