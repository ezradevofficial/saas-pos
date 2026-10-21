<?php

namespace Modules\HR\Tests;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
use Modules\HR\Leave\Accruals;
use Modules\HR\Leave\LeaveLedger;
use Modules\HR\Leave\LeaveRequestType;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveEntry;
use Modules\HR\Models\LeaveType;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-12 to HR-15, HR-33, APR-02, APR-07: statutory types from the pack
// blocked until confirmed, accrual and balance maths, requests counted on
// working days and approved by the line manager, cancellation.
class LeaveTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    private Employee $boss;

    private User $bossUser;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        // HR-12 to HR-15: the fixed leave dates below are in the future of 14 October 2026 (the
        // accrual date), and a request is cancelled "before it ends": the clock is fixed there.
        $this->travelTo(CarbonImmutable::parse('2026-10-14T09:00:00Z'));

        Notification::fake();
        $this->setUpHr();
        $this->assertSame(0, Artisan::call('country-packs:holidays', ['code' => 'KE']));
        app(BusinessCalendar::class)->forgetHolidays();

        $this->bossUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $this->boss = $this->employee(['first_name' => 'Boss', 'user_id' => $this->bossUser->id]);
        $this->worker = $this->employee(['first_name' => 'Worker', 'reports_to_id' => $this->boss->id]);
    }

    public function test_statutory_types_need_their_days_confirmed_before_requests_or_accrual(): void
    {
        // A type whose days are still needed (as copied before the pack had researched days).
        $annual = $this->inTenant(function () {
            $type = LeaveType::query()->where('statutory_key', 'annual')->sole();
            $type->forceFill(['entitlement_days' => null, 'needs_confirmation' => true, 'research' => null, 'qualifying_months' => null, 'accrues_from' => null])->save();

            return $type;
        });

        $this->request($annual, '2026-10-19', '2026-10-20')->assertUnprocessable()->assertJsonPath('code', 'leave_days_needed');
        $run = $this->postJson('/api/v1/hr/leave-accruals/run', ['company_id' => $this->acme->id, 'as_of' => '2026-10-14'], $this->headersFor($this->hrOfficer))->assertOk()->json('data');
        $this->assertSame(0, $run['posted']);
        $this->assertContains('ANNUAL', array_column($run['skipped_types'], 'code'));

        // Applying the pack again copies nothing twice, and fills a type still needed with the pack's researched days (CP-01).
        $this->postJson('/api/v1/hr/leave-types/apply-pack', ['company_id' => $this->acme->id], $this->headersFor($this->hrOfficer))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.statutory_key', 'annual')->assertJsonPath('data.0.entitlement_days', '21.00')->assertJsonPath('data.0.researched', true);
        $this->postJson('/api/v1/hr/leave-types/apply-pack', ['company_id' => $this->acme->id], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonCount(0, 'data');
        $this->inTenant(fn () => $annual->refresh()->forceFill(['entitlement_days' => null, 'needs_confirmation' => true, 'research' => null, 'qualifying_months' => null, 'accrues_from' => null])->save());

        // The company enters and confirms the days: from then on they are its own.
        $this->patchJson("/api/v1/hr/leave-types/{$annual->id}", ['confirm' => true], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'entitlement_required');
        $this->patchJson("/api/v1/hr/leave-types/{$annual->id}", ['entitlement_days' => '21', 'confirm' => true, 'accrues_from' => '2026-01-01'], $this->headersFor($this->hrOfficer))
            ->assertOk()->assertJsonPath('data.days_needed', false)->assertJsonPath('data.entitlement_days', '21.00')->assertJsonPath('data.researched', false);
        $this->assertSame(2, $this->postJson('/api/v1/hr/leave-accruals/run', ['company_id' => $this->acme->id, 'as_of' => '2026-10-14'], $this->headersFor($this->hrOfficer))->json('data.posted'));
    }

    public function test_annual_accrual_is_pro_rated_idempotent_and_expires_above_the_carry_over_cap(): void
    {
        $type = $this->type(['code' => 'AL', 'accrual' => 'annual', 'entitlement_days' => '21', 'carry_over_max_days' => '5']);
        $accruals = app(Accruals::class);

        // Hired 5 January: 361 of 365 days of 2026, 21 × 361 / 365 = 20.77.
        $this->inTenant(fn () => $accruals->run(CarbonImmutable::parse('2026-10-14')));
        $this->assertSame('20.77', $this->balance($type, '2026-12-31'));
        $this->assertSame(0, $this->inTenant(fn () => $accruals->run(CarbonImmutable::parse('2026-10-14'))['posted']));

        // Five days taken, approved.
        $this->approve($this->request($type, '2026-10-26', '2026-10-30')->assertCreated()->json('data.id'));
        $this->assertSame('15.77', $this->balance($type, '2026-12-31'));

        // New year: 10.77 above the cap of 5 expires on 1 January, then the year's 21.
        $this->inTenant(fn () => $accruals->run(CarbonImmutable::parse('2027-01-01')));
        $this->assertSame('26.00', $this->balance($type, '2027-01-01'));
        $expiry = $this->inTenant(fn () => LeaveEntry::query()->where('kind', 'expiry')->where('employee_id', $this->worker->id)->sole());
        $this->assertSame(['-10.77', '2027-01-01'], [(string) $expiry->days, $expiry->effective_on->toDateString()]);

        // Summary for the balances page.
        $summary = $this->inTenant(fn () => app(LeaveLedger::class)->summary($this->worker->id, $type, CarbonImmutable::parse('2026-11-01')));
        $this->assertSame(['entitled' => '20.77', 'taken' => '5.00', 'balance' => '15.77', 'pending' => '0.00', 'available' => '15.77'], $summary);
    }

    public function test_monthly_accrual_credits_each_ended_month_pro_rated(): void
    {
        $type = $this->type(['code' => 'ML', 'accrual' => 'monthly', 'entitlement_days' => '12']);

        // January: 27 of 31 days employed (0.87); February to September: 1 each; October has not ended.
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14')));
        $this->assertSame('8.87', $this->balance($type, '2026-10-14'));
        $this->assertSame(9, $this->inTenant(fn () => LeaveEntry::query()->where('leave_type_id', $type->id)->where('employee_id', $this->worker->id)->count()));

        // A leaver accrues up to the termination date only.
        $this->postJson("/api/v1/hr/employees/{$this->worker->id}/terminate", ['termination_date' => '2026-11-15', 'reason' => 'resignation'], $this->headersFor($this->hrOfficer))->assertOk();
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2027-02-01')));
        // October 1.00 and 15 of 30 November days: 0.50.
        $this->assertSame('10.37', $this->balance($type, '2027-02-01'));
    }

    public function test_a_request_counts_working_days_goes_to_the_line_manager_and_posts_its_days_once_approved(): void
    {
        Event::fake([LeaveApproved::class, LeaveCancelled::class]);
        $type = $this->type(['code' => 'AL', 'accrual' => 'annual', 'entitlement_days' => '21']);
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14')));

        // Monday 19 to Friday 23 October 2026: Tuesday 20 is Mashujaa Day (country pack); a half day on Friday.
        $request = $this->request($type, '2026-10-19', '2026-10-23', ['end_half' => true])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame('3.50', $request['days']);
        $this->assertSame(['2026-10-19', '2026-10-21', '2026-10-22', '2026-10-23'], array_column($request['dates'], 'date'));
        $this->assertMatchesRegularExpression('/^LV-2026-00001$/', $request['number']);

        // APR-02: the line manager's user is asked.
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $request['id'])->sole());
        $this->assertSame([$this->bossUser->id], $this->inTenant(fn () => ApprovalAssignment::query()->where('request_id', $approval->id)->pluck('user_id')->all()));

        // Pending days count against what is available; overlaps are refused.
        $this->request($type, '2026-10-22', '2026-10-27')->assertUnprocessable()->assertJsonPath('code', 'leave_overlap');
        $this->request($type, '2026-11-02', '2026-12-31')->assertUnprocessable()->assertJsonPath('code', 'leave_balance_short');

        $this->approve($request['id'], $this->bossUser);
        $this->assertSame('17.27', $this->balance($type, '2026-12-31'));
        Event::assertDispatched(LeaveApproved::class, fn (LeaveApproved $e) => $e->requestId === $request['id'] && $e->days === '3.50' && $e->paid && count($e->dates) === 4);

        // Cancelled before it ends: the days come back.
        $this->postJson("/api/v1/hr/leave-requests/{$request['id']}/cancel", ['reason' => 'Plans changed'], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('20.77', $this->balance($type, '2026-12-31'));
        Event::assertDispatched(LeaveCancelled::class, fn (LeaveCancelled $e) => $e->requestId === $request['id'] && $e->days === '3.50');
    }

    public function test_without_a_line_manager_the_hr_officers_decide_and_the_employee_never_approves_their_own(): void
    {
        $type = $this->type(['code' => 'UL', 'accrual' => 'none', 'entitlement_days' => '10', 'paid' => false]);
        // Asked by the owner on the boss's behalf (whoever asks never approves either, APR-07).
        $request = $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $this->boss->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-03'], $this->headersFor())
            ->assertCreated()->json('data');

        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $request['id'])->sole());
        $assigned = $this->inTenant(fn () => ApprovalAssignment::query()->where('request_id', $approval->id)->pluck('user_id')->all());
        $this->assertSame([$this->hrOfficer->id], $assigned);
        $this->assertSame($this->bossUser->id, $this->inTenant(fn () => app(LeaveRequestType::class)->requesterId($request['id'])));

        // Self-service: the boss asks for their own leave and cancels it; another employee's request is not found.
        $own = $this->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $type->id, 'start_date' => '2026-12-07', 'end_date' => '2026-12-08'], $this->headersFor($this->bossUser))->assertCreated()->json('data');
        $this->postJson("/api/v1/hr/me/leave-requests/{$own['id']}/cancel", [], $this->headersFor($this->bossUser))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $workerRequest = $this->request($type, '2026-11-09', '2026-11-09')->assertCreated()->json('data.id');
        $this->postJson("/api/v1/hr/me/leave-requests/{$workerRequest}/cancel", [], $this->headersFor($this->bossUser))->assertNotFound();

        // Untracked types allow at most their days per request.
        $this->request($type, '2026-11-16', '2026-12-04')->assertUnprocessable()->assertJsonPath('code', 'leave_balance_short');

        $this->assertSame(['2026-12-07', '2026-11-02'], array_column($this->getJson('/api/v1/hr/me/leave', $this->headersFor($this->bossUser))->assertOk()->json('data.requests'), 'start_date'));
        $this->getJson('/api/v1/hr/leave-calendar?from=2026-11-01&to=2026-11-30', $this->headersFor($this->hrOfficer))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_new_types_accrual_starts_on_the_companys_day_not_utcs(): void
    {
        // HR-12: a type's accrual starts today by default.
        $this->atNairobiHalfPastMidnight();
        $created = $this->postJson('/api/v1/hr/leave-types', ['company_id' => $this->acme->id, 'code' => 'CL', 'name' => 'Compassionate', 'count_basis' => 'calendar_days', 'accrual' => 'annual', 'entitlement_days' => '5'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data');
        $this->assertSame('2026-10-20', $created['accrues_from']);
    }

    public function test_accruals_run_as_of_the_companys_day_not_utcs(): void
    {
        // HR-13: someone hired today (the company's today) accrues.
        $this->atNairobiHalfPastMidnight();
        $type = $this->type(['code' => 'AL', 'accrual' => 'annual', 'entitlement_days' => '21']);
        $hired = $this->employee(['first_name' => 'New', 'hire_date' => '2026-10-20']);
        $this->postJson('/api/v1/hr/leave-accruals/run', ['company_id' => $this->acme->id], $this->headersFor($this->hrOfficer))->assertOk();
        $this->assertTrue($this->inTenant(fn () => LeaveEntry::query()->where('employee_id', $hired->id)->where('leave_type_id', $type->id)->exists()));
    }

    public function test_balances_default_to_the_companys_day_not_utcs(): void
    {
        // HR-15: with or without a company filter.
        $this->atNairobiHalfPastMidnight();
        $headers = $this->headersFor($this->hrOfficer);
        $this->type(['code' => 'AL', 'accrual' => 'annual', 'entitlement_days' => '21']);
        $this->getJson("/api/v1/hr/leave-balances?company={$this->acme->id}", $headers)->assertOk()->assertJsonPath('meta.as_of', '2026-10-20');
        $this->getJson('/api/v1/hr/leave-balances', $headers)->assertOk()->assertJsonPath('meta.as_of', '2026-10-20');
    }

    public function test_an_adjustment_takes_effect_on_the_companys_day_by_default_not_utcs(): void
    {
        // HR-15.
        $this->atNairobiHalfPastMidnight();
        $type = $this->type(['code' => 'AL', 'accrual' => 'annual', 'entitlement_days' => '21']);
        $this->postJson('/api/v1/hr/leave-adjustments', ['employee_id' => $this->worker->id, 'leave_type_id' => $type->id, 'days' => '1', 'reason' => 'Carried in'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->assertJsonPath('data.effective_on', '2026-10-20');
    }

    public function test_leave_that_ended_on_the_companys_yesterday_is_no_longer_cancelled(): void
    {
        // HR-14: Monday 19th is over in Nairobi, though not yet in UTC.
        $this->atNairobiHalfPastMidnight();
        $type = $this->type(['code' => 'UL', 'accrual' => 'none', 'entitlement_days' => '10', 'paid' => false]);
        $past = $this->request($type, '2026-10-19', '2026-10-19')->assertCreated()->json('data.id');
        $this->approve($past);
        $this->postJson("/api/v1/hr/leave-requests/{$past}/cancel", ['reason' => 'Late'], $this->headersFor($this->hrOfficer))->assertStatus(409)->assertJsonPath('code', 'document_not_open');
    }

    public function test_the_leave_calendar_shows_the_companys_month_by_default(): void
    {
        // 00:30 on Sunday 1 November 2026 in Nairobi; still 31 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-31T21:30:00Z'));
        $this->organisationTokens = []; // Signed in again: the sessions of 14 October have timed out.
        $type = $this->type(['code' => 'UL', 'accrual' => 'none', 'entitlement_days' => '10', 'paid' => false]);
        $this->request($type, '2026-11-02', '2026-11-03')->assertCreated();

        $this->getJson("/api/v1/hr/leave-calendar?company={$this->acme->id}", $this->headersFor($this->hrOfficer))->assertOk()->assertJsonCount(1, 'data');
    }

    /** 00:30 on Tuesday 20 October 2026 in Nairobi; still Monday 19 October in UTC. Signed in again: the sessions of 14 October have timed out. */
    private function atNairobiHalfPastMidnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T21:30:00Z'));
        $this->organisationTokens = [];
    }

    /** @param array<string, mixed> $data */
    private function type(array $data): LeaveType
    {
        $id = $this->postJson('/api/v1/hr/leave-types', [
            'company_id' => $this->acme->id, 'name' => $data['code'], 'count_basis' => 'working_days', 'accrues_from' => '2026-01-01', ...$data,
        ], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');

        return $this->inTenant(fn () => LeaveType::query()->findOrFail($id));
    }

    private function request(LeaveType $type, string $start, string $end, array $extra = [], ?Employee $employee = null): TestResponse
    {
        return $this->postJson('/api/v1/hr/leave-requests', [
            'employee_id' => ($employee ?? $this->worker)->id, 'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end, ...$extra,
        ], $this->headersFor($this->hrOfficer));
    }

    private function approve(string $requestId, ?User $by = null): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $requestId)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($by ?? $this->bossUser))->assertOk();
    }

    private function balance(LeaveType $type, string $asOf): string
    {
        return $this->inTenant(fn () => app(LeaveLedger::class)->balance($this->worker->id, $type->id, CarbonImmutable::parse($asOf)));
    }
}
