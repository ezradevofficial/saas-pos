<?php

namespace Modules\HR\Tests\Concerns;

use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Testing\TestResponse;
use Modules\HR\Models\Employee;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with the HR module active (BuildsOrganisation's Acme, Kenya,
 * Africa/Nairobi, KES): the Sales department, an HR Officer for the whole
 * company, a Branch Manager at branch A, and helpers to hire employees
 * through the API.
 */
trait BuildsHr
{
    use BuildsOrganisation;

    protected Department $sales;

    protected User $hrOfficer;

    protected User $managerA;

    protected function setUpHr(): void
    {
        $this->setUpOrganisation();

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('hr');
            // CUR-01: the company's currencies (KES) are active.
            app(TenantCurrencies::class)->provisionFor($this->acme);
            $this->sales = Department::create(['company_id' => $this->acme->id, 'code' => 'SALES', 'name' => 'Sales']);
        });

        $this->hrOfficer = $this->userWith('hr_officer', Scope::company($this->acme->id));
        $this->managerA = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
    }

    /** @param array<string, mixed> $extra */
    protected function hire(array $extra = [], ?User $by = null): TestResponse
    {
        return $this->postJson('/api/v1/hr/employees', [
            'company_id' => $this->acme->id,
            'first_name' => 'Amina',
            'last_name' => 'Otieno',
            'hire_date' => '2026-01-05',
            'branch_id' => $this->branchA->id,
            ...$extra,
        ], $this->headersFor($by ?? $this->hrOfficer));
    }

    /** @param array<string, mixed> $extra */
    protected function employee(array $extra = []): Employee
    {
        $id = $this->hire($extra)->assertCreated()->json('data.id');

        return $this->inTenant(fn () => Employee::query()->findOrFail($id));
    }
}
