<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Models\TimeRule;
use Modules\HR\Support\Digest;
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

    public function test_a_till_upload_of_500_punches_recomputes_each_day_once_within_a_query_budget(): void
    {
        // HR-22, HR-25: ten employees, five days each of five in-and-out pairs, in one upload (the request's maximum).
        $employees = [$this->amina];

        for ($i = 1; $i < 10; $i++) {
            $employees[] = $this->employee(['first_name' => "Staff {$i}", 'location_id' => $this->locationA->id, 'clock_methods' => ['till']]);
        }

        $till = $this->pairTill($this->locationA);
        $today = CarbonImmutable::now('Africa/Nairobi')->startOfDay();
        $punches = [];

        foreach ($employees as $employee) {
            for ($back = 5; $back >= 1; $back--) {
                for ($pair = 0; $pair < 5; $pair++) {
                    $in = $today->subDays($back)->setTime(7 + 2 * $pair, 0);
                    $punches[] = ['id' => (string) Str::uuid7(), 'employee_id' => $employee->id, 'kind' => 'in', 'occurred_at' => $in->toIso8601String()];
                    $punches[] = ['id' => (string) Str::uuid7(), 'employee_id' => $employee->id, 'kind' => 'out', 'occurred_at' => $in->addMinutes(90)->toIso8601String()];
                }
            }
        }

        $this->assertCount(500, $punches);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $results = $this->postJson('/api/v1/hr/till/punches', ['punches' => $punches], $this->deviceHeaders($till))->assertOk()->json('data');

        $this->assertSame(['stored'], array_values(array_unique(array_column($results, 'status'))));
        // Measured: 4,045 queries with the days recomputed once per upload; 10,014 when every punch
        // recomputed its day and the rest of its week (the code before this fix), well over the budget.
        $this->assertLessThan(4800, $queries);
        // Each day: five pairs of 90 minutes.
        $days = $this->inTenant(fn () => AttendanceDay::query()->get());
        $this->assertCount(50, $days);
        $this->assertSame([450], $days->pluck('worked_minutes')->unique()->values()->all());
    }

    public function test_rekeying_digests_reaches_suspended_tenants(): void
    {
        // HR-10, decision 24: a suspended tenant's digests must move to the new key too.
        config(['hr.digest_key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->putJson("/api/v1/hr/employees/{$this->amina->id}/identifiers", ['identifiers' => [['type' => 'national_id', 'value' => '12345678']]], $this->headersFor($this->hrOfficer))->assertOk();
        $this->inTenant(fn () => Tenant::query()->firstOrFail()->forceFill(['status' => 'suspended'])->save());

        config(['hr.digest_key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        $this->assertSame(0, Artisan::call('hr:rekey-digests'));
        $this->assertSame(Digest::of('national_id', '12345678'), $this->inTenant(fn () => EmployeeIdentifier::query()->sole()->value_digest));
    }

    public function test_personal_contact_details_never_reach_the_audit_log(): void
    {
        // HR-11, HR-37, decision 27: the change is recorded by name, never its value.
        $contacts = [
            'personal_email' => 'amina.home@example.com', 'personal_phone' => '+254700000009',
            'address' => ['line1' => '12 Moi Avenue', 'city' => 'Nairobi'], 'emergency_contacts' => [['name' => 'Peter Otieno', 'relationship' => 'Brother', 'phone' => '+254700000002']],
        ];
        $hired = $this->employee(['first_name' => 'Grace', ...$contacts]);
        $this->patchJson("/api/v1/hr/employees/{$this->amina->id}", [...$contacts, 'work_phone' => '+254711000000'], $this->headersFor($this->hrOfficer))->assertOk();

        $entries = $this->inTenant(fn () => AuditEntry::query()->where('action', 'like', 'hr.employee.%')->get());
        $logged = $entries->map(fn (AuditEntry $entry) => json_encode([$entry->before, $entry->after]))->implode("\n");

        foreach (['amina.home@example.com', '+254700000009', 'Moi Avenue', 'Peter Otieno'] as $value) {
            $this->assertStringNotContainsString($value, $logged);
        }

        $this->assertStringContainsString('+254711000000', $logged);
        $named = fn (string $id) => $entries->where('action', 'hr.employee.sensitive_update')->where('auditable_id', $id)->flatMap(fn (AuditEntry $entry) => $entry->after['fields'])->sort()->values()->all();
        $this->assertSame(['address', 'emergency_contacts', 'personal_email', 'personal_phone'], $named($this->amina->id));
        $this->assertSame(['address', 'emergency_contacts', 'personal_email', 'personal_phone'], $named($hired->id));
    }

    public function test_saving_a_time_rule_recomputes_the_open_days_of_its_dates(): void
    {
        // HR-20, HR-25, decision 29: two days of nine hours; one approved, one open.
        $headers = $this->headersFor($this->hrOfficer);
        $monday = CarbonImmutable::now('Africa/Nairobi')->startOfWeek()->subWeek();

        foreach ([$monday, $monday->addDay()] as $date) {
            foreach ([['in', $date->setTime(8, 0)], ['out', $date->setTime(17, 0)]] as [$kind, $at]) {
                $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => $kind, 'occurred_at' => $at->toIso8601String(), 'note' => 'Reader down'], $headers)->assertCreated();
            }
        }

        $days = $this->inTenant(fn () => AttendanceDay::query()->orderBy('work_date')->get());
        $this->assertSame([60, 60], $days->pluck('overtime_minutes')->all());
        $this->postJson('/api/v1/hr/timesheets/approve', ['day_ids' => [$days[0]->id]], $headers)->assertOk();

        // The rule in force from 1 January now counts overtime after 7 hours.
        $rule = $this->inTenant(fn () => TimeRule::query()->sole()->id);
        $this->patchJson("/api/v1/hr/time-rules/{$rule}", ['daily_overtime_after_minutes' => 420], $headers)->assertOk();
        $this->assertSame([60, 120], $this->inTenant(fn () => AttendanceDay::query()->orderBy('work_date')->pluck('overtime_minutes')->all()));

        // A new rule from the open day on (the old one ends the day before) recomputes it too.
        $this->patchJson("/api/v1/hr/time-rules/{$rule}", ['effective_to' => $monday->toDateString()], $headers)->assertOk();
        $this->assertContains('no_time_rule', $this->inTenant(fn () => AttendanceDay::query()->orderBy('work_date')->get()[1]->exceptions));
        $this->postJson('/api/v1/hr/time-rules', ['company_id' => $this->acme->id, 'effective_from' => $monday->addDay()->toDateString(), 'daily_overtime_after_minutes' => 480], $headers)->assertCreated();
        $this->assertSame([60, 60], $this->inTenant(fn () => AttendanceDay::query()->orderBy('work_date')->pluck('overtime_minutes')->all()));
    }

    public function test_approving_days_recomputes_them_first(): void
    {
        // HR-25, decision 29: an approval never freezes stale minutes.
        $headers = $this->headersFor($this->hrOfficer);
        $date = CarbonImmutable::now('Africa/Nairobi')->startOfWeek()->subWeek()->addDays(2);

        foreach ([['in', $date->setTime(8, 0)], ['out', $date->setTime(16, 0)]] as [$kind, $at]) {
            $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => $kind, 'occurred_at' => $at->toIso8601String(), 'note' => 'Reader down'], $headers)->assertCreated();
        }

        $day = $this->inTenant(fn () => AttendanceDay::query()->sole());
        // Stale values (as if a change had not been recomputed yet).
        $this->assertSame(1, $this->inTenant(fn () => AttendanceDay::query()->whereKey($day->id)->update(['worked_minutes' => 10, 'regular_minutes' => 10, 'overtime_minutes' => 0])));

        $this->postJson('/api/v1/hr/timesheets/approve', ['day_ids' => [$day->id]], $headers)->assertOk()->assertJsonPath('data.approved', 1);
        $approved = $this->inTenant(fn () => AttendanceDay::query()->sole());
        $this->assertSame([480, 480], [$approved->worked_minutes, $approved->regular_minutes]);
        $this->assertNotNull($approved->approved_at);
    }
}
