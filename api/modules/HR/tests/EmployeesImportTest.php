<?php

namespace Modules\HR\Tests;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Position;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-34, IMP-01 to IMP-03: employees imported from a file through the API's
// own Form Request and the hire path, updated by number, and undone until
// HR records depend on them.
class EmployeesImportTest extends TestCase
{
    use BuildsHr, BuildsImports, RefreshTenantDatabase;

    private const HEADERS = ['Company', 'Employee number', 'First name', 'Last name', 'Gender', 'Hire date', 'Branch', 'Department', 'Position', 'Line manager number', 'Clock-in methods', 'Work email'];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('media');
        $this->setUpHr();
        $this->inTenant(fn () => Position::create(['company_id' => $this->acme->id, 'code' => 'CASHIER', 'title' => 'Cashier']));
    }

    public function test_employees_are_imported_with_their_places_and_managers_and_bad_rows_are_reported(): void
    {
        $this->assertContains('employees', array_column($this->getJson('/api/v1/imports/importers', $this->headersFor($this->hrOfficer))->assertOk()->json('data'), 'key'));

        $import = $this->importFile('employees', $this->csvFile([self::HEADERS,
            ['Acme', 'E100', 'Amina', 'Otieno', 'female', '2026-01-05', 'Branch A', 'SALES', 'CASHIER', '', 'till; web', 'amina@example.com'],
            ['Acme', 'E101', 'Baraka', 'Mwangi', 'male', '2026-02-01', 'Branch A', 'Sales', '', 'E100', 'till', ''],
            ['Acme', '', 'Chausiku', 'Njeri', '', '2026-03-01', 'Branch B', '', '', '', '', ''],
            ['Nowhere Ltd', 'E103', 'Dan', 'Kariuki', '', '2026-03-01', '', '', '', '', '', ''],
            ['Acme', 'E104', 'Esther', 'Wanjiru', 'unknown', '2026-03-01', 'Branch A', 'NOPE', '', '', '', ''],
        ]), user: $this->hrOfficer);

        $this->assertSame([3, 2], [$import['created_rows'], $import['failed_rows']]);
        $employees = $this->inTenant(fn () => Employee::query()->with(['department', 'position', 'manager'])->orderBy('first_name')->get());
        $this->assertSame(['Amina', 'Baraka', 'Chausiku'], $employees->pluck('first_name')->all());
        $this->assertSame(['E100', 'E101'], [$employees[0]->number, $employees[1]->number]);
        $this->assertMatchesRegularExpression('/^EMP-\d{5}$/', $employees[2]->number);
        $this->assertSame(['SALES', 'CASHIER', ['till', 'web']], [$employees[0]->department->code, $employees[0]->position->code, $employees[0]->clock_methods]);
        $this->assertSame('E100', $employees[1]->manager->number);
        $this->assertSame($this->branchB->id, $employees[2]->branch_id);

        // Updated by number in upsert mode; the before image allows undo.
        $update = $this->importFile('employees', $this->csvFile([self::HEADERS, ['Acme', 'E100', 'Amina', 'Odhiambo', 'female', '2026-01-05', 'Branch A', 'SALES', 'CASHIER', '', 'till', 'amina@example.com']]), 'upsert', $this->hrOfficer);
        $this->assertSame(1, $update['updated_rows']);
        $this->assertSame('Odhiambo', $this->inTenant(fn () => Employee::query()->where('number', 'E100')->value('last_name')));
        $this->postJson("/api/v1/imports/{$update['id']}/undo", [], $this->headersFor($this->hrOfficer))->assertStatus(202);
        $this->assertSame('Otieno', $this->inTenant(fn () => Employee::query()->where('number', 'E100')->value('last_name')));
    }

    public function test_an_import_is_undone_until_hr_records_depend_on_its_employees(): void
    {
        $file = fn (string $number) => $this->csvFile([self::HEADERS, ['Acme', $number, 'Amina', 'Otieno', '', '2026-01-05', 'Branch A', '', '', '', '', '']]);

        $kept = $this->importFile('employees', $file('E200'), user: $this->hrOfficer);
        $employee = $this->inTenant(fn () => Employee::query()->where('number', 'E200')->sole());
        $this->postJson("/api/v1/hr/employees/{$employee->id}/contracts", ['type' => 'permanent', 'start_date' => '2026-01-05', 'pay_basis' => 'monthly', 'pay_amount' => '30000', 'currency' => 'KES'], $this->headersFor($this->hrOfficer))->assertCreated();
        $this->postJson("/api/v1/imports/{$kept['id']}/undo", [], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'import_undo_blocked');

        $undone = $this->importFile('employees', $file('E201'), user: $this->hrOfficer);
        $this->postJson("/api/v1/imports/{$undone['id']}/undo", [], $this->headersFor($this->hrOfficer))->assertStatus(202);
        $this->assertSame(['terminated', '2026-01-05', 'other'], $this->inTenant(function () {
            $e = Employee::query()->where('number', 'E201')->sole();

            return [$e->status, $e->termination_date->toDateString(), $e->termination_reason];
        }));

        // A branch manager (no hr.employee.create) cannot import employees.
        $this->assertNotContains('employees', array_column($this->getJson('/api/v1/imports/importers', $this->headersFor($this->managerA))->json('data') ?? [], 'key'));
    }
}
