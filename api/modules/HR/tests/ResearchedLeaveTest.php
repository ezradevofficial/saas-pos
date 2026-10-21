<?php

namespace Modules\HR\Tests;

use App\Core\Workflow\Calendar\BusinessCalendar;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\HR\Contracts\LeaveRecords;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Leave\Accruals;
use Modules\HR\Leave\LeaveLedger;
use Modules\HR\Leave\LeaveRequests;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveEntry;
use Modules\HR\Models\LeaveType;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * CP-01, HR-03, HR-12, HR-13, HR-14 on the SHIPPED packs (researched
 * values, docs/country-packs-research-2026-10.md): KE statutory leave
 * (annual 21 working days after 12 months, maternity 90 calendar days,
 * paternity 14, sick 30 full + 15 half after 2 months), CD maternity 14
 * weeks and circumstantial leave with its events, CD annual and sick still
 * "Days needed"; the KRA PIN and national ID formats; leave records say
 * when they used researched days.
 */
class ResearchedLeaveTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
    }

    /** @return array<string, LeaveType> by statutory key */
    private function types(): array
    {
        return $this->inTenant(fn () => LeaveType::query()->where('company_id', $this->acme->id)->whereNotNull('statutory_key')->get()->keyBy('statutory_key')->all());
    }

    private function request(Employee $employee, LeaveType $type, string $start, string $end, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end, ...$extra], $this->headersFor($this->hrOfficer));
    }

    public function test_kenya_statutory_leave_entitlements_and_rules(): void
    {
        $this->assertSame(0, Artisan::call('country-packs:holidays', ['code' => 'KE']));
        app(BusinessCalendar::class)->forgetHolidays();
        $worker = $this->employee(['first_name' => 'Worker']);
        $types = $this->types();

        $row = fn (LeaveType $t) => [(string) $t->entitlement_days, $t->count_basis, $t->accrual, $t->paid, $t->qualifying_months, $t->half_pay_days === null ? null : (string) $t->half_pay_days, $t->needs_confirmation];
        $this->assertSame(['21.00', 'working_days', 'annual', true, 12, null, false], $row($types['annual']));
        $this->assertSame(['90.00', 'calendar_days', 'none', true, null, null, false], $row($types['maternity']));
        $this->assertSame(['14.00', 'calendar_days', 'none', true, null, null, false], $row($types['paternity']));
        $this->assertSame(['30.00', 'working_days', 'annual', true, 2, '15.00', false], $row($types['sick']));

        foreach ($types as $type) {
            $this->assertSame(['medium', '2026-10-17'], [$type->research['confidence'], $type->research['researched_on']]);
            $this->assertNotEmpty($type->research['source']);
        }

        $this->getJson("/api/v1/hr/leave-types?company_id={$this->acme->id}", $this->headersFor($this->hrOfficer))->assertOk()
            ->assertJsonPath('data.0.researched', true);

        // Annual leave after 12 months of service (hired 2026-01-05): refused before 2027-01-05.
        $this->request($worker, $types['annual'], '2026-10-26', '2026-10-27')->assertUnprocessable()
            ->assertJsonPath('code', 'leave_qualifying_service')->assertJsonValidationErrors('start_date');

        // Sick leave: after 2 months; 30 + 15 days are credited a year, the days beyond 30 are at half pay.
        $this->request($worker, $types['sick'], '2026-02-23', '2026-02-24')->assertUnprocessable()->assertJsonPath('code', 'leave_qualifying_service');
        $this->patchJson("/api/v1/hr/leave-types/{$types['sick']->id}", ['accrues_from' => '2026-01-01'], $this->headersFor($this->hrOfficer))->assertOk()
            ->assertJsonPath('data.researched', true);
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14'), $this->acme->id));
        // 45 × 361 ÷ 365 (hired 5 January), from researched days.
        $accrual = $this->inTenant(fn () => LeaveEntry::query()->where('leave_type_id', $types['sick']->id)->where('kind', 'accrual')->sole());
        $this->assertSame(['44.51', true], [(string) $accrual->days, $accrual->researched]);

        $first = $this->request($worker, $types['sick'], '2026-03-09', '2026-04-17')->assertCreated()->json('data');
        $this->assertTrue($first['researched']);
        $firstDays = BigDecimal::of($first['days']);
        $this->assertTrue($firstDays->isLessThanOrEqualTo(30));
        $this->assertSame('0.00', $first['half_pay_days']);
        $second = $this->request($worker, $types['sick'], '2026-05-04', '2026-05-08')->assertCreated()->json('data');
        $expected = BigDecimal::max(BigDecimal::of($second['days'])->minus(BigDecimal::of(30)->minus($firstDays)), 0)->toScale(2);
        $this->assertSame((string) $expected, $second['half_pay_days']);
        $this->assertTrue(BigDecimal::of($second['half_pay_days'])->isPositive());

        // Maternity: at most 90 calendar days a request.
        $mother = $this->employee(['first_name' => 'Mother', 'hire_date' => '2025-01-06']);
        $this->request($mother, $types['maternity'], '2026-11-02', '2027-01-31')->assertUnprocessable()->assertJsonPath('code', 'leave_balance_short');
        $this->request($mother, $types['maternity'], '2026-11-02', '2027-01-30')->assertCreated()->assertJsonPath('data.days', '90.00')
            ->assertJsonPath('data.researched', true);
    }

    public function test_a_company_that_changes_researched_days_owns_them(): void
    {
        $this->employee();
        $annual = $this->types()['annual'];

        $this->patchJson("/api/v1/hr/leave-types/{$annual->id}", ['entitlement_days' => '24'], $this->headersFor($this->hrOfficer))->assertOk()
            ->assertJsonPath('data.researched', false)->assertJsonPath('data.entitlement_days', '24.00');
        $this->inTenant(fn () => $this->assertNull($annual->refresh()->research));
    }

    public function test_congo_maternity_and_circumstantial_leave_with_events(): void
    {
        $this->inTenant(fn () => $this->acme->forceFill(['country' => 'CD', 'timezone' => 'Africa/Kinshasa'])->save());
        $worker = $this->employee(['first_name' => 'Joseph']);
        $types = $this->types();

        // Low confidence: annual and sick leave stay "Days needed".
        $this->assertTrue($types['annual']->needs_confirmation);
        $this->assertNull($types['annual']->entitlement_days);
        $this->assertTrue($types['sick']->needs_confirmation);
        $this->request($worker, $types['annual'], '2026-10-26', '2026-10-27')->assertUnprocessable()->assertJsonPath('code', 'leave_days_needed');

        // Maternity 14 weeks; its pay rate is not confirmed (paid null): requests are taken, Payroll blocks the run.
        $this->assertSame(['98.00', 'calendar_days', false, null], [(string) $types['maternity']->entitlement_days, $types['maternity']->count_basis, $types['maternity']->needs_confirmation, $types['maternity']->paid]);
        Event::fake([LeaveApproved::class]);
        $mother = $this->employee(['first_name' => 'Mother', 'hire_date' => '2025-01-06']);
        $maternity = $this->request($mother, $types['maternity'], '2026-11-02', '2026-11-30')->assertCreated()->json('data.id');
        $this->inTenant(fn () => app(LeaveRequests::class)->completed($maternity, 'approved', null));
        Event::assertDispatched(LeaveApproved::class, fn (LeaveApproved $e) => $e->requestId === $maternity && $e->paid === null);
        // Payroll's sync reads it too (the HR contract), with its unconfirmed pay.
        $records = $this->inTenant(fn () => collect(app(LeaveRecords::class)->unpaidOfCompany($this->acme->id))->keyBy('request_id'));
        $this->assertNull($records[$maternity]['paid']);

        // Circumstantial: 10 working days a year; own marriage 2, birth 2.
        $circumstantial = $types['circumstantial'];
        $this->assertSame(['10.00', 'annual'], [(string) $circumstantial->entitlement_days, $circumstantial->accrual]);
        $this->assertSame([['key' => 'marriage', 'days' => 2], ['key' => 'birth', 'days' => 2]], $circumstantial->events);
        $this->patchJson("/api/v1/hr/leave-types/{$circumstantial->id}", ['accrues_from' => '2026-01-01'], $this->headersFor($this->hrOfficer))->assertOk();
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14'), $this->acme->id));

        $this->request($worker, $circumstantial, '2026-10-26', '2026-10-28', ['event' => 'marriage'])->assertUnprocessable()
            ->assertJsonPath('code', 'leave_event_days')->assertJsonValidationErrors('end_date');
        $this->request($worker, $circumstantial, '2026-10-26', '2026-10-27', ['event' => 'funeral'])->assertUnprocessable()->assertJsonPath('code', 'leave_event_unknown');
        $this->request($worker, $circumstantial, '2026-10-26', '2026-10-27', ['event' => 'marriage'])->assertCreated()
            ->assertJsonPath('data.event', 'marriage')->assertJsonPath('data.event_label', 'Own marriage')->assertJsonPath('data.researched', true);
    }

    public function test_reapplying_the_pack_fills_a_types_days_and_accrual_without_touching_company_edits(): void
    {
        // An existing CD company copied its types before the pack had researched days: circumstantial leave
        // with no accrual and no days; the company renamed it and asks for a document.
        $this->inTenant(fn () => $this->acme->forceFill(['country' => 'CD', 'timezone' => 'Africa/Kinshasa'])->save());
        $worker = $this->employee(['first_name' => 'Joseph']);
        $circumstantial = $this->inTenant(function () {
            $type = LeaveType::query()->where('company_id', $this->acme->id)->where('statutory_key', 'circumstantial')->sole();
            $type->forceFill(['accrual' => 'none', 'entitlement_days' => null, 'needs_confirmation' => true, 'events' => null, 'research' => null,
                'name' => 'Congé de circonstance', 'requires_document' => true, 'accrues_from' => null])->save();

            return $type;
        });

        $this->postJson('/api/v1/hr/leave-types/apply-pack', ['company_id' => $this->acme->id], $this->headersFor($this->hrOfficer))->assertOk()
            ->assertJsonPath('data.0.statutory_key', 'circumstantial');
        $type = $this->inTenant(fn () => $circumstantial->refresh());
        // A yearly cap of 10 days, with the events; the company's name and document rule are kept.
        $this->assertSame(['annual', '10.00', false, 'Congé de circonstance', true], [$type->accrual, (string) $type->entitlement_days, $type->needs_confirmation, $type->name, $type->requires_document]);
        $this->assertSame([['key' => 'marriage', 'days' => 2], ['key' => 'birth', 'days' => 2]], $type->events);

        $this->patchJson("/api/v1/hr/leave-types/{$type->id}", ['accrues_from' => '2026-01-01'], $this->headersFor($this->hrOfficer))->assertOk();
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14'), $this->acme->id));
        // The yearly cap accrues: 10 days × 361 ÷ 365 for a hire on 5 January.
        $this->assertSame('9.89', $this->inTenant(fn () => app(LeaveLedger::class)->balance($worker->id, $type->id, CarbonImmutable::parse('2026-12-31'))));
    }

    public function test_kenya_identifier_formats(): void
    {
        $employee = $this->employee();
        $url = "/api/v1/hr/employees/{$employee->id}/identifiers";

        // KRA PIN: A or P, 9 digits, a letter (checked upper-cased, without spaces or dashes).
        foreach (['A012345678Z', 'p051234567q', 'A 012-345-678 Z'] as $pin) {
            $this->putJson($url, ['identifiers' => [['type' => 'kra_pin', 'value' => $pin]]], $this->headersFor($this->hrOfficer))->assertOk();
        }

        foreach (['B012345678Z', 'A01234567Z', 'A0123456789', 'A0123456789Z'] as $pin) {
            $this->putJson($url, ['identifiers' => [['type' => 'kra_pin', 'value' => $pin]]], $this->headersFor($this->hrOfficer))
                ->assertUnprocessable()->assertJsonPath('code', 'identifier_format');
        }

        // National ID: 7 to 9 digits.
        $this->putJson($url, ['identifiers' => [['type' => 'national_id', 'value' => '1234567']]], $this->headersFor($this->hrOfficer))->assertOk();
        $this->putJson($url, ['identifiers' => [['type' => 'national_id', 'value' => '123456']]], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'identifier_format');
        $this->putJson($url, ['identifiers' => [['type' => 'national_id', 'value' => '1234567890']]], $this->headersFor($this->hrOfficer))->assertUnprocessable();
    }
}
