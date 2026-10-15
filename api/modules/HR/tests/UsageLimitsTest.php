<?php

namespace Modules\HR\Tests;

use App\Core\Limits\EmployeeCount;
use App\Core\Limits\Models\Overage;
use App\Core\Limits\Models\UsageMeter;
use App\Core\Limits\Units;
use App\Core\Limits\UsageCounter;
use App\Core\Limits\UsageLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Employees\ActiveEmployees;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\BuildsUsageLimits;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-32, LIM-01, LIM-03, LIM-04: HR's employee count meters active
// employees; hiring and rehiring are refused past the overage window,
// while clocking in, leave and period close are never blocked.
class UsageLimitsTest extends TestCase
{
    use BuildsHr, BuildsUsageLimits, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->setUpLimits();
    }

    public function test_the_core_counts_active_employees_through_hr(): void
    {
        $this->assertInstanceOf(ActiveEmployees::class, app(EmployeeCount::class));

        $leaver = $this->employee(['first_name' => 'Leaver']);
        $this->employee(['first_name' => 'Stayer']);
        $this->postJson("/api/v1/hr/employees/{$leaver->id}/terminate", ['termination_date' => '2026-02-01', 'reason' => 'resignation'], $this->headersFor($this->hrOfficer))->assertOk();

        $this->assertSame(1, $this->inTenant(fn () => app(UsageCounter::class)->count(Units::EMPLOYEE)));
        $this->allow([Units::EMPLOYEE => 5]);
        $this->meter(CarbonImmutable::parse('2026-10-14T03:00:00Z'));
        $this->assertSame(1, $this->inTenant(fn () => UsageMeter::query()->where('unit', Units::EMPLOYEE)->value('count')));
    }

    public function test_hiring_and_rehiring_are_refused_once_the_window_has_passed_and_clocking_in_never_is(): void
    {
        $leaver = $this->employee(['first_name' => 'Leaver']);
        $this->postJson("/api/v1/hr/employees/{$leaver->id}/terminate", ['termination_date' => '2026-02-01', 'reason' => 'resignation'], $this->headersFor($this->hrOfficer))->assertOk();
        $staying = $this->employee(['first_name' => 'Staying']);
        $this->employee(['first_name' => 'Second']);

        // Two active employees against an allowance of one, past the 7-day window.
        $this->allow([Units::EMPLOYEE => 1], priced: false);
        $this->meter();
        $this->inTenant(fn () => Overage::query()->open()->get()->each(fn (Overage $o) => $o->forceFill(['started_at' => now()->subDays(8)])->save()));

        $this->hire(['first_name' => 'Third'])->assertStatus(402)->assertJsonPath('code', 'usage_limit_reached')->assertJsonPath('unit', 'employee');
        $this->postJson("/api/v1/hr/employees/{$leaver->id}/rehire", ['hire_date' => '2026-10-01'], $this->headersFor($this->hrOfficer))->assertStatus(402);
        $this->assertSame(2, $this->inTenant(fn () => app(UsageCounter::class)->count(Units::EMPLOYEE)));

        // LIM-04: the employees already there keep working.
        $this->postJson('/api/v1/hr/punches', ['employee_id' => $staying->id, 'kind' => 'in', 'occurred_at' => now()->subHour()->toIso8601String(), 'note' => 'Back office'], $this->headersFor($this->hrOfficer))->assertCreated();
        $this->patchJson("/api/v1/hr/employees/{$staying->id}", ['work_phone' => '+254700000003'], $this->headersFor($this->hrOfficer))->assertOk();

        // Back within the plan: hiring works again (only the meter closes the overage).
        $this->allow([Units::EMPLOYEE => 3], priced: false);
        $this->meter();
        $this->hire(['first_name' => 'Third'])->assertCreated();
        $this->assertSame('within', $this->inTenant(fn () => collect(app(UsageLimits::class)->status(CarbonImmutable::now())['units'])->firstWhere('unit', 'employee')['state']));
    }
}
