<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Models\Employee;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Stage 7C closing fixes (HR-20, HR-22, HR-25, HR-26, HR-37, NFR-04):
// the offline window before a period closes, punch uploads recomputed once
// per day, time rules and approvals recomputing their days.
class ClosingFixesTest extends TestCase
{
    use BuildsHr, BuildsTill, RefreshTenantDatabase;

    private Employee $amina;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->amina = $this->employee(['location_id' => $this->locationA->id, 'clock_methods' => ['till', 'web', 'device'], 'biometric_ref' => '1001']);
        $this->postJson('/api/v1/hr/time-rules', [
            'company_id' => $this->acme->id, 'effective_from' => '2026-01-01', 'daily_overtime_after_minutes' => 480,
            'weekly_overtime_after_minutes' => 2700, 'night_start' => '22:00', 'night_end' => '06:00', 'late_grace_minutes' => 10, 'rest_days' => ['sun'],
        ], $this->headersFor($this->hrOfficer))->assertCreated();
    }

    public function test_a_period_closes_only_after_the_tills_offline_window_unless_forced_with_a_reason(): void
    {
        $today = CarbonImmutable::now('Africa/Nairobi')->startOfDay();
        $headers = $this->headersFor($this->hrOfficer);
        $older = $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => $today->subDays(30)->toDateString(), 'end_date' => $today->subDays(8)->toDateString()], $headers)->assertCreated()->json('data.id');
        $recent = $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => $today->subDays(7)->toDateString(), 'end_date' => $today->subDays(3)->toDateString()], $headers)->assertCreated()->json('data.id');

        // NFR-04: a punch of the 3rd day back may still come from a till for 7 days after it.
        $this->postJson("/api/v1/hr/attendance-periods/{$recent}/close", [], $headers)->assertUnprocessable()
            ->assertJsonPath('code', 'period_offline_window')->assertJsonPath('window_ends', $today->addDays(4)->toDateString());
        $this->postJson("/api/v1/hr/attendance-periods/{$recent}/close", ['force' => true], $headers)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/hr/attendance-periods/{$recent}/close", ['force' => true, 'reason' => 'Payroll cut-off today'], $headers)->assertOk()->assertJsonPath('data.status', 'closed');
        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.attendance_period.force_close')->sole());
        $this->assertSame(['reason' => 'Payroll cut-off today', 'offline_window_ends' => $today->addDays(4)->toDateString()], $entry->after);

        // A period whose window is over closes without forcing (the last day was 8 days ago).
        $this->postJson("/api/v1/hr/attendance-periods/{$older}/close", [], $headers)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.attendance_period.force_close')->count()));
    }
}
