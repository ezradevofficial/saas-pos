<?php

namespace Modules\Payroll\Tests;

use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
use Modules\Payroll\Models\Attendance;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Models\Settings;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PAY-09 to PAY-12: overtime and premiums from HR's closed attendance at
// the company's multipliers, unpaid leave from HR's leave events, pro-rated
// pay for a hire in the month, a USD salary with CDF statutory figures
// (CUR-04) on fictional fixture rates.
class PayCalculationTest extends TestCase
{
    use BuildsExchangeRates, BuildsPayroll, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll();
    }

    private function settings(array $values): void
    {
        $this->putJson("/api/v1/payroll/settings/{$this->acme->id}", $values, $this->headersFor($this->officer))->assertOk();
    }

    private function closePeriod(PayrollEmployee $employee, array $minutes, string $periodId = '0195e000-0000-7000-8000-000000000001'): void
    {
        event(new AttendancePeriodClosed($employee->tenant_id, $periodId, $this->acme->id, '2026-10-01', '2026-10-31', 'Africa/Nairobi', null, [[
            'employee_id' => $employee->employee_id, 'days_present' => 20, 'days_absent' => 0, 'days_leave' => 0, 'worked_minutes' => 10000,
            'regular_minutes' => 9000, 'overtime_minutes' => 0, 'night_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0,
            'late_minutes' => 0, 'early_leave_minutes' => 0, ...$minutes,
        ]]));
    }

    public function test_overtime_and_premiums_come_from_hr_at_the_company_multipliers_and_are_paid_once(): void
    {
        $amina = $this->payrollEmployee('5000000');
        // Hourly rate: KES 50,000 ÷ 200 hours = KES 250.
        $this->settings(['overtime_multiplier' => '1.5', 'night_multiplier' => '1.25', 'holiday_multiplier' => '2', 'monthly_hours' => '200', 'monthly_days' => '25']);
        $this->closePeriod($amina, ['overtime_minutes' => 600, 'night_minutes' => 120, 'holiday_minutes' => 60, 'rest_day_minutes' => 30]);

        $id = $this->postedRun();
        $line = $this->lines($id)[$amina->number];
        $earnings = collect($line['earnings'])->keyBy('code');

        // 10 h × 250 × 1.5; 2 h × 250 × 0.25 on top; 1 h × 250 × 1; rest day without a multiplier: unpaid premium, warned.
        $this->assertSame('375000', $earnings['overtime']['amount']['amount_minor']);
        $this->assertSame('1.5', $earnings['overtime']['multiplier']);
        $this->assertSame('12500', $earnings['night']['amount']['amount_minor']);
        $this->assertSame('25000', $earnings['holiday']['amount']['amount_minor']);
        $this->assertArrayNotHasKey('rest_day', $earnings->all());
        $this->assertSame('rest_day_not_paid', $line['warnings'][0]['code']);
        $this->assertSame('5412500', $line['gross']['amount_minor']);

        // Paid once: the next month's run does not pay the October period again.
        $this->assertNotNull($this->inTenant(fn () => Attendance::query()->where('employee_id', $amina->employee_id)->value('paid_run_id')));
        $november = $this->calculatedRun('2026-11-15');
        $this->assertSame('5000000', $this->lines($november)[$amina->number]['gross']['amount_minor']);
    }

    public function test_overtime_needs_the_monthly_hours(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->settings(['overtime_multiplier' => '1.5']);
        $this->closePeriod($amina, ['overtime_minutes' => 60]);

        $line = $this->lines($this->calculatedRun())[$amina->number];
        $this->assertSame('monthly_hours_missing', $line['errors'][0]['code']);
    }

    public function test_unpaid_leave_is_deducted_at_the_daily_rate_and_cancelled_leave_is_not(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->settings(['monthly_days' => '25']);
        $tenant = $amina->tenant_id;
        $dates = [['date' => '2026-10-12', 'fraction' => '1.00'], ['date' => '2026-10-13', 'fraction' => '0.50']];
        event(new LeaveApproved($tenant, '0195e000-0000-7000-8000-0000000000a1', 'LV-2026-00001', $amina->employee_id, $this->acme->id, '0195e000-0000-7000-8000-0000000000b1', 'UNPAID', false, '2026-10-12', '2026-10-13', false, true, '1.50', $dates));
        // Paid leave changes nothing.
        event(new LeaveApproved($tenant, '0195e000-0000-7000-8000-0000000000a2', 'LV-2026-00002', $amina->employee_id, $this->acme->id, '0195e000-0000-7000-8000-0000000000b2', 'ANNUAL', true, '2026-10-20', '2026-10-20', false, false, '1.00', [['date' => '2026-10-20', 'fraction' => '1.00']]));

        $id = $this->calculatedRun();
        $line = $this->lines($id)[$amina->number];
        $leave = collect($line['earnings'])->firstWhere('code', 'unpaid_leave');
        // 1.5 days × KES 50,000 ÷ 25.
        $this->assertSame('-300000', $leave['amount']['amount_minor']);
        $this->assertSame('4700000', $line['gross']['amount_minor']);

        event(new LeaveCancelled($tenant, '0195e000-0000-7000-8000-0000000000a1', $amina->employee_id, $this->acme->id, '0195e000-0000-7000-8000-0000000000b1', '1.50', $dates));
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertOk();
        $this->assertSame('5000000', $this->lines($id)[$amina->number]['gross']['amount_minor']);
    }

    public function test_a_hire_in_the_month_is_pro_rated_by_calendar_days(): void
    {
        // Hired on 17 October: 15 of 31 days.
        $baraka = $this->payrollEmployee('3100000', ['first_name' => 'Baraka', 'hire_date' => '2026-10-17']);
        $line = $this->lines($this->calculatedRun())[$baraka->number];

        $this->assertSame('1500000', $line['basic']['amount_minor']);
        $this->assertSame(15, $line['inputs']['days'][0]['days']);
    }

    public function test_a_usd_salary_with_cdf_statutory_figures_stores_its_rates_and_base_amounts(): void
    {
        $this->inTenant(function () {
            $this->acme->forceFill(['base_currency' => 'USD', 'country' => 'CD'])->save();
            app(TenantCurrencies::class)->provisionFor($this->acme);
            TenantCurrency::query()->whereIn('code', ['USD', 'CDF'])->update(['active' => true]);
        });
        app(PayrollPack::class)->fake('CD', FixturePacks::cd());
        $this->rate('USD', 'CDF', '2850', at: '2026-10-01T00:00:00Z');
        $this->rate('USD', 'CDF', '9999', at: '2026-11-15T00:00:00Z');

        $joseph = $this->payrollEmployee('100000', ['first_name' => 'Joseph'], 'USD');
        $id = $this->calculatedRun();
        $line = $this->lines($id)[$joseph->number];
        $statutory = collect($line['statutory_lines'])->keyBy('code');

        $this->assertSame('USD', $line['pay_currency']);
        $this->assertSame('CDF', $line['statutory_currency']);
        // USD 1,000 = CDF 2,850,000 at the pay date's rate (a later rate changes nothing).
        $this->assertEquals(['rate' => '2850.00000000', 'base' => 'USD', 'quote' => 'CDF'], array_intersect_key($line['fx'][0], array_flip(['rate', 'base', 'quote'])));
        $this->assertSame('2736000', $line['taxable_pay']['amount_minor']);
        $this->assertSame('CDF', $line['taxable_pay']['currency']);
        $this->assertSame('655800', $statutory['ipr']['employee_statutory']['amount_minor']);
        // Back in USD, each component rounded once: 655,800 ÷ 2850 = 230.105… → USD 230.11.
        $this->assertSame('23011', $statutory['ipr']['employee']['amount_minor']);
        $this->assertSame('4000', $statutory['cnss']['employee']['amount_minor']);
        $this->assertSame('72989', $line['net']['amount_minor']);
        $this->assertSame('12500', $line['employer']['amount_minor']);
        // Base currency (USD) amounts.
        $this->assertSame('72989', $line['net_base']['amount_minor']);

        // No rate on the pay date: a line error that blocks submission.
        $this->inTenant(fn () => ExchangeRate::query()->delete());
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertOk();
        $this->assertSame('rate_missing', $this->lines($id)[$joseph->number]['errors'][0]['code']);
    }

    public function test_settings_are_company_data_with_permission_checks(): void
    {
        $this->getJson("/api/v1/payroll/settings/{$this->acme->id}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.overtime_multiplier', null)->assertJsonPath('data.premiums_taxable', true);
        $this->settings(['overtime_multiplier' => '1.5', 'monthly_days' => '26']);
        $this->assertSame('1.5000', (string) $this->inTenant(fn () => Settings::query()->value('overtime_multiplier')));
        $this->getJson("/api/v1/payroll/settings/{$this->acme->id}", $this->headersFor($this->officer))->assertJsonPath('data.overtime_multiplier', '1.5')->assertJsonPath('data.monthly_days', '26');
        $this->putJson("/api/v1/payroll/settings/{$this->acme->id}", ['night_multiplier' => '0.5'], $this->headersFor($this->officer))->assertUnprocessable();

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson("/api/v1/payroll/settings/{$this->acme->id}", $this->headersFor($manager))->assertNotFound();
        $other = $this->otherTenant();
        $this->getJson("/api/v1/payroll/settings/{$other['company']->id}", $this->headersFor($this->officer))->assertNotFound();
    }
}
