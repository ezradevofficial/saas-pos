<?php

namespace Modules\HR\Imports;

use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\HR\Employees\EmployeeEntity;
use Modules\HR\Employees\Employees;
use Modules\HR\Http\Requests\StoreEmployeeRequest;
use Modules\HR\Http\Requests\TerminateEmployeeRequest;
use Modules\HR\Http\Requests\UpdateEmployeeRequest;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Position;

/**
 * IMP-01, HR-34: employees. Company, branch, location and department by
 * name (departments also by code), position by code, line manager by
 * number; matched by number in update mode. Every row passes the API's
 * own Form Request (places, field rules, the create permission at the
 * employee's place) and the hire path, usage guard included (HR-32).
 * Sensitive data (identifiers, accounts, pay) is never imported. Undo of a
 * created employee terminates it on its hire date (reason `other`), only
 * when nothing depends on it (RecordUsage `hr_employee`).
 */
class EmployeesImporter extends Importer
{
    private const ATTRIBUTES = ['first_name', 'middle_name', 'last_name', 'gender', 'work_email', 'work_phone', 'personal_email', 'personal_phone', 'branch_id', 'location_id', 'department_id', 'position_id', 'reports_to_id', 'clock_methods', 'biometric_ref', 'hire_date'];

    public function key(): string
    {
        return 'employees';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function customFieldEntity(): ?string
    {
        return EmployeeEntity::KEY;
    }

    public function usageEntity(): ?string
    {
        return EmployeeEntity::KEY;
    }

    public function permissions(string $mode): array
    {
        return $mode === 'upsert' ? ['hr.employee.create', 'hr.employee.edit'] : ['hr.employee.create'];
    }

    public function matchColumns(): array
    {
        return ['number'];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('company', true),
            ImportColumn::text('number'),
            ImportColumn::text('first_name', true),
            ImportColumn::text('middle_name'),
            ImportColumn::text('last_name', true),
            ImportColumn::enum('gender', Employee::GENDERS, 'hr.genders'),
            ImportColumn::of('hire_date', 'date', true),
            ImportColumn::text('work_email'),
            ImportColumn::text('work_phone'),
            ImportColumn::text('personal_email'),
            ImportColumn::text('personal_phone'),
            ImportColumn::text('branch'),
            ImportColumn::text('location'),
            ImportColumn::text('department'),
            ImportColumn::text('position'),
            ImportColumn::text('manager_number'),
            ImportColumn::of('clock_methods', 'list'),
            ImportColumn::text('biometric_ref'),
        ];
    }

    protected function fieldColumns(): array
    {
        return [
            'company_id' => 'company', 'number' => 'number', 'first_name' => 'first_name', 'middle_name' => 'middle_name', 'last_name' => 'last_name',
            'gender' => 'gender', 'hire_date' => 'hire_date', 'work_email' => 'work_email', 'work_phone' => 'work_phone', 'personal_email' => 'personal_email',
            'personal_phone' => 'personal_phone', 'branch_id' => 'branch', 'location_id' => 'location', 'department_id' => 'department',
            'position_id' => 'position', 'reports_to_id' => 'manager_number', 'clock_methods' => 'clock_methods', 'biometric_ref' => 'biometric_ref',
        ];
    }

    public function example(): array
    {
        return [
            'company' => 'Main company', 'number' => '', 'first_name' => 'Amina', 'last_name' => 'Otieno', 'gender' => 'female', 'hire_date' => '2026-01-05',
            'work_email' => 'amina@example.com', 'work_phone' => '+254722000111', 'branch' => 'Main branch', 'department' => 'SALES', 'position' => 'CASHIER',
            'clock_methods' => 'till', 'biometric_ref' => '1001',
        ];
    }

    public function identity(array $values): ?string
    {
        return isset($values['number']) && $values['number'] !== '' ? Str::lower(($values['company'] ?? '').'|'.$values['number']) : null;
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $errors = [];
        $input = [];
        $byName = fn (string $key, string $model, string $column = 'name', ?callable $scope = null) => $context->remember($key, fn () => $model::query()
            ->when($scope !== null, $scope)->get(['id', $column])->groupBy(fn ($row) => Str::lower((string) $row->{$column}))->map(fn ($rows) => $rows->pluck('id')->all())->all());

        $companies = $byName('companies', Company::class, scope: fn ($q) => $q->whereNull('archived_at'));
        $companyIds = $companies[Str::lower($values['company'] ?? '')] ?? [];
        $companyId = count($companyIds) === 1 ? $companyIds[0] : null;

        if ($companyId === null) {
            throw new RowRejected(['company' => [__(count($companyIds) > 1 ? 'imports.errors.ambiguous' : 'imports.errors.unknown', ['value' => $values['company'] ?? ''])]]);
        }

        $input['company_id'] = $companyId;

        foreach (['number', 'first_name', 'middle_name', 'last_name', 'gender', 'hire_date', 'work_email', 'work_phone', 'personal_email', 'personal_phone', 'biometric_ref'] as $key) {
            if (array_key_exists($key, $values)) {
                $input[$key] = $values[$key];
            }
        }

        if (array_key_exists('clock_methods', $values)) {
            $input['clock_methods'] = array_map('strtolower', $values['clock_methods']);
        }

        $lookups = [
            'branch' => ['branch_id', fn () => $byName("branches|{$companyId}", Branch::class, scope: fn ($q) => $q->where('company_id', $companyId)->whereNull('archived_at'))],
            'location' => ['location_id', fn () => $byName("locations|{$companyId}", Location::class, scope: fn ($q) => $q->whereIn('branch_id', Branch::query()->where('company_id', $companyId)->select('id'))->whereNull('archived_at'))],
            'department' => ['department_id', fn () => array_merge_recursive(
                $byName("departments|{$companyId}", Department::class, scope: fn ($q) => $q->where('company_id', $companyId)->whereNull('archived_at')),
                $byName("department_codes|{$companyId}", Department::class, 'code', fn ($q) => $q->where('company_id', $companyId)->whereNull('archived_at')),
            )],
            'position' => ['position_id', fn () => $byName("positions|{$companyId}", Position::class, 'code', fn ($q) => $q->where('company_id', $companyId)->whereNull('archived_at'))],
            'manager_number' => ['reports_to_id', fn () => $byName("managers|{$companyId}", Employee::class, 'number', fn ($q) => $q->where('company_id', $companyId))],
        ];

        foreach ($lookups as $column => [$field, $options]) {
            if (! array_key_exists($column, $values)) {
                continue;
            }

            $ids = array_values(array_unique($options()[Str::lower($values[$column])] ?? []));

            count($ids) === 1
                ? $input[$field] = $ids[0]
                : $errors[$column][] = __($ids === [] ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $values[$column]]);
        }

        $custom = $this->customInput($values);

        if ($custom !== []) {
            $input['custom'] = $custom;
        }

        if ($errors !== []) {
            throw new RowRejected($errors);
        }

        $existing = isset($values['number']) && $values['number'] !== ''
            ? Employee::query()->where('company_id', $companyId)->whereRaw('lower(number) = lower(?)', [$values['number']])->first()
            : null;

        if ($existing !== null && ! $context->updates()) {
            throw new RowRejected(['number' => [__('imports.errors.exists', ['value' => $values['number']])]]);
        }

        if ($existing !== null) {
            unset($input['company_id'], $input['number']);
        }

        return new PreparedRow($number, $input, $existing);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = $row->creates()
            ? app(DomainRequests::class)->validate(StoreEmployeeRequest::class, $row->input, $context->user)
            : app(DomainRequests::class)->validate(UpdateEmployeeRequest::class, $row->input, $context->user, ['hr_employee' => $row->record]);
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        $employees = app(Employees::class);

        if ($row->creates()) {
            return ['record' => $employees->hire($row->validated, $context->user), 'before' => null];
        }

        $employee = Employee::query()->whereKey($row->record->getKey())->firstOrFail();
        $before = [...$employee->only(self::ATTRIBUTES), 'hire_date' => $employee->hire_date->toDateString(), 'custom' => (array) ($employee->custom ?? [])];

        return ['record' => $employees->update($employee, $row->validated, $context->user), 'before' => $before];
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $employee = Employee::query()->whereKey($row->record_id)->first();

        if ($employee === null) {
            return;
        }

        $requests = app(DomainRequests::class);

        if ($row->action === 'created') {
            if ($employee->isActive()) {
                $data = $requests->validate(TerminateEmployeeRequest::class, ['termination_date' => $employee->hire_date->toDateString(), 'reason' => 'other', 'note' => __('hr.imports.undone')], $context->user, ['hr_employee' => $employee]);
                app(Employees::class)->terminate($employee, $data, $context->user);
            }

            return;
        }

        $before = $row->before ?? [];
        $input = [];

        foreach (self::ATTRIBUTES as $key) {
            $now = $key === 'hire_date' ? $employee->hire_date->toDateString() : $employee->{$key};

            if (array_key_exists($key, $before) && $before[$key] != $now) {
                $input[$key] = $before[$key];
            }
        }

        $custom = self::customRestore((array) ($before['custom'] ?? []), (array) ($employee->custom ?? []));

        if ($custom !== []) {
            $input['custom'] = $custom;
        }

        if ($input !== []) {
            app(Employees::class)->update($employee, $requests->validate(UpdateEmployeeRequest::class, $input, $context->user, ['hr_employee' => $employee]), $context->user);
        }
    }

    public function companiesOf(array $ids): array
    {
        return Employee::query()->whereIn('id', $ids)->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['hr.employee.view'];
    }

    public function describe(Model $record): string
    {
        return trim($record->number.' '.$record->displayName());
    }
}
