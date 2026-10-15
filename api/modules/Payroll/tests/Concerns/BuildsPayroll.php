<?php

namespace Modules\Payroll\Tests\Concerns;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Testing\TestResponse;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Statutory\PayrollPack;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with Payroll active (and HR unless asked otherwise), Acme
 * (Kenya, Africa/Nairobi, KES), the Sales department, a Payroll Officer
 * for the company, the fictional Kenya-shaped fixture pack injected
 * (FixturePacks, never the real pack), and helpers to hire employees
 * through HR's API, give them a contract and put them in a monthly pay
 * group.
 */
trait BuildsPayroll
{
    use BuildsOrganisation;

    protected Department $sales;

    protected User $officer;

    protected PayGroup $monthly;

    protected function setUpPayroll(bool $withHr = true, ?array $pack = null): void
    {
        $this->setUpOrganisation();

        $this->inTenant(function () use ($withHr) {
            if ($withHr) {
                app(ModuleRegistry::class)->activate('hr');
            }

            app(ModuleRegistry::class)->activate('payroll');
            app(TenantCurrencies::class)->provisionFor($this->acme);
            $this->sales = Department::create(['company_id' => $this->acme->id, 'code' => 'SALES', 'name' => 'Sales']);
        });

        app(PayrollPack::class)->fake('KE', $pack ?? FixturePacks::ke());
        $this->officer = $this->userWith('payroll_officer', Scope::company($this->acme->id));
        $this->monthly = $this->inTenant(fn () => PayGroup::query()->create(['company_id' => $this->acme->id, 'code' => 'MONTHLY', 'name' => 'Monthly staff', 'frequency' => 'monthly', 'pay_day' => 28]));
    }

    /** Hire an employee through HR (open with Payroll alone, decision B) with a monthly contract; their payroll copy, in the monthly group. */
    protected function payrollEmployee(string $payMinor = '5000000', array $extra = [], string $currency = 'KES', array $setup = [], array $contract = []): PayrollEmployee
    {
        $id = $this->postJson('/api/v1/hr/employees', [
            'company_id' => $this->acme->id, 'first_name' => 'Amina', 'last_name' => 'Otieno', 'hire_date' => '2026-01-05',
            'branch_id' => $this->branchA->id, 'department_id' => $this->sales->id, ...$extra,
        ], $this->headersFor())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/hr/employees/{$id}/contracts", [
            'type' => 'permanent', 'start_date' => $extra['hire_date'] ?? '2026-01-05', 'pay_basis' => 'monthly',
            'pay_amount' => $currency === 'CDF' ? $payMinor : substr($payMinor, 0, -2).'.'.substr($payMinor, -2), 'currency' => $currency, ...$contract,
        ], $this->headersFor())->assertCreated();

        $employee = $this->inTenant(fn () => PayrollEmployee::query()->where('employee_id', $id)->firstOrFail());
        $this->patchJson("/api/v1/payroll/employees/{$employee->id}", ['pay_group_id' => $this->monthly->id, 'payment_method' => 'bank', ...$setup], $this->headersFor($this->officer))->assertOk();

        return $this->inTenant(fn () => $employee->refresh());
    }

    protected function createRun(string $periodDate = '2026-10-15', ?User $by = null): TestResponse
    {
        return $this->postJson('/api/v1/payroll/runs', ['pay_group_id' => $this->monthly->id, 'period_date' => $periodDate], $this->headersFor($by ?? $this->officer));
    }

    /** A run created and calculated by the officer; its id. */
    protected function calculatedRun(string $periodDate = '2026-10-15'): string
    {
        $id = $this->createRun($periodDate)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertOk();

        return $id;
    }

    /** Calculated, submitted by the officer, approved by the Owner, posted by the officer. */
    protected function postedRun(string $periodDate = '2026-10-15'): string
    {
        $id = $this->calculatedRun($periodDate);
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headersFor($this->officer))->assertOk();
        $this->approve($id);
        $this->postJson("/api/v1/payroll/runs/{$id}/post", [], $this->headersFor($this->officer))->assertOk()->assertJsonPath('data.status', 'posted');

        return $id;
    }

    protected function approve(string $runId, ?User $by = null): TestResponse
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $runId)->where('status', 'pending')->firstOrFail());

        return $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($by ?? $this->owner));
    }

    /** @return array<string, mixed> the run's lines by employee number */
    protected function lines(string $runId, ?User $by = null): array
    {
        $rows = $this->getJson("/api/v1/payroll/runs/{$runId}/lines?per_page=100", $this->headersFor($by ?? $this->officer))->assertOk()->json('data');

        return collect($rows)->keyBy(fn (array $l) => $l['employee']['number'])->all();
    }
}
