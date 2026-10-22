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
use Modules\HR\Models\EmployeeIdentifier;
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
 * weeks at 2/3 of pay, annual 18 days after 12 months plus a day per five
 * years of service (low confidence, owner decision 2026-10-22) and
 * circumstantial leave with its events, CD sick still "Days needed"; the
 * KRA PIN and national ID formats; leave records say when they used
 * researched days, and when those are low confidence.
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

        // The statute's 12 months are rolling, not the calendar year: January 2027 still counts the 2026 requests.
        $third = $this->request($worker, $types['sick'], '2027-01-11', '2027-01-15')->assertCreated()->json('data');
        $this->assertSame('5.00', $third['half_pay_days']);

        // Cancelling the first request frees its full-pay days: the later ones are counted again.
        $this->postJson("/api/v1/hr/leave-requests/{$first['id']}/cancel", ['reason' => 'Entered by mistake'], $this->headersFor($this->hrOfficer))->assertOk();
        $this->getJson("/api/v1/hr/leave-requests/{$second['id']}", $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.half_pay_days', '0.00');
        $this->getJson("/api/v1/hr/leave-requests/{$third['id']}", $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.half_pay_days', '0.00');

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

        // Owner decision 2026-10-22 (art. 141): adults 12 working days after 12 months (medium), under 18 18 days (low), +1 day per 5 years.
        $annual = $types['annual'];
        $this->assertSame(['12.00', false, 12, 5, 1, 'medium'], [(string) $annual->entitlement_days, $annual->needs_confirmation, $annual->qualifying_months, $annual->seniority['every_years'], $annual->seniority['days'], $annual->research['confidence']]);
        $this->assertSame([18, 18, 'low'], [$annual->under_age['age'], $annual->under_age['days'], $annual->under_age['confidence']]);
        $this->assertSame(['date_of_birth'], $annual->research['assumed']);
        $listed = collect($this->getJson("/api/v1/hr/leave-types?company_id={$this->acme->id}", $this->headersFor($this->hrOfficer))->assertOk()->json('data'))->keyBy('statutory_key');
        // The annual type includes the low-confidence under-18 days; maternity at 2/3 is medium.
        $this->assertSame([true, false, '2/3'], [$listed['annual']['low_confidence'], $listed['maternity']['low_confidence'], $listed['maternity']['pay_share']]);
        $this->assertFalse($listed['circumstantial']['low_confidence']);
        // Sick leave: the Code gives 2/3 pay for the whole incapacity but no number of days: still "Days needed".
        $this->assertTrue($types['sick']->needs_confirmation);
        $this->request($worker, $types['sick'], '2026-10-26', '2026-10-27')->assertUnprocessable()->assertJsonPath('code', 'leave_days_needed');

        // Maternity 14 weeks at 2/3 of pay (art. 130): requests are taken, and Payroll withholds the other third.
        $this->assertSame(['98.00', 'calendar_days', false, true, '2/3'], [(string) $types['maternity']->entitlement_days, $types['maternity']->count_basis, $types['maternity']->needs_confirmation, $types['maternity']->paid, $types['maternity']->pay_share]);
        Event::fake([LeaveApproved::class]);
        $mother = $this->employee(['first_name' => 'Mother', 'hire_date' => '2025-01-06']);
        $maternity = $this->request($mother, $types['maternity'], '2026-11-02', '2026-11-30')->assertCreated()
            ->assertJsonPath('data.researched', true)->assertJsonPath('data.low_confidence', false)->json('data.id');
        $this->inTenant(fn () => app(LeaveRequests::class)->completed($maternity, 'approved', null));
        Event::assertDispatched(LeaveApproved::class, fn (LeaveApproved $e) => $e->requestId === $maternity && $e->paid === true && $e->payShare === '2/3');
        // Payroll's sync reads it too (the HR contract), with its share of pay.
        $records = $this->inTenant(fn () => collect(app(LeaveRecords::class)->unpaidOfCompany($this->acme->id))->keyBy('request_id'));
        $this->assertSame([true, '2/3'], [$records[$maternity]['paid'], $records[$maternity]['pay_share']]);

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

    public function test_congo_annual_leave_by_age_and_seniority(): void
    {
        $this->inTenant(fn () => $this->acme->forceFill(['country' => 'CD', 'timezone' => 'Africa/Kinshasa'])->save());
        $senior = $this->employee(['first_name' => 'Senior', 'hire_date' => '2015-06-01', 'date_of_birth' => '1980-02-01']);
        $junior = $this->employee(['first_name' => 'Junior', 'hire_date' => '2024-01-01', 'date_of_birth' => '1990-05-01']);
        // 16 on 1 January 2026: the under-18 days.
        $minor = $this->employee(['first_name' => 'Minor', 'hire_date' => '2025-01-01', 'date_of_birth' => '2009-09-01']);
        // Turns 18 on 2 January 2026: still 17 on 1 January, the start of the accrual period, so the under-18 days.
        $almost = $this->employee(['first_name' => 'Almost', 'hire_date' => '2025-01-01', 'date_of_birth' => '2008-01-02']);
        // No date of birth: the adult days (assumed).
        $unknown = $this->employee(['first_name' => 'Unknown', 'hire_date' => '2025-01-01', 'date_of_birth' => null]);
        $annual = $this->types()['annual'];
        $this->patchJson("/api/v1/hr/leave-types/{$annual->id}", ['accrues_from' => '2026-01-01'], $this->headersFor($this->hrOfficer))->assertOk()
            ->assertJsonPath('data.researched', true);
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14'), $this->acme->id));
        $balance = fn (Employee $e) => $this->inTenant(fn () => app(LeaveLedger::class)->balance($e->id, $annual->id, CarbonImmutable::parse('2026-12-31')));
        // Adults: hired 2015-06-01, 10 full years on 1 January 2026, so 12 + 2 = 14 days; hired 2024: 2 years, 12 days.
        $this->assertSame(['14.00', '12.00'], [$balance($senior), $balance($junior)]);
        // Under 18 at the start of the year: 18 days; no date of birth: 12.
        $this->assertSame(['18.00', '18.00', '12.00'], [$balance($minor), $balance($almost), $balance($unknown)]);
    }

    public function test_reapplying_the_pack_gives_an_old_copy_its_maternity_pay(): void
    {
        // A CD company copied maternity before 2026-10-22, with its pay unconfirmed (paid null).
        $this->inTenant(fn () => $this->acme->forceFill(['country' => 'CD', 'timezone' => 'Africa/Kinshasa'])->save());
        $this->employee(['first_name' => 'Joseph']);
        $maternity = $this->inTenant(function () {
            $type = LeaveType::query()->where('company_id', $this->acme->id)->where('statutory_key', 'maternity')->sole();
            $type->forceFill(['paid' => null, 'pay_share' => null, 'research' => ['confidence' => 'medium', 'researched_on' => '2026-10-17', 'source' => ['https://example.org'], 'assumed' => []]])->save();

            return $type;
        });

        $this->postJson('/api/v1/hr/leave-types/apply-pack', ['company_id' => $this->acme->id], $this->headersFor($this->hrOfficer))->assertOk();
        $type = $this->inTenant(fn () => $maternity->refresh());
        $this->assertSame([true, '2/3', 'medium', '2026-10-22'], [$type->paid, $type->pay_share, $type->research['confidence'], $type->research['researched_on']]);

        // A company that made the type its own (research cleared) keeps its pay.
        $this->inTenant(fn () => $type->forceFill(['paid' => null, 'pay_share' => null, 'research' => null])->save());
        $this->postJson('/api/v1/hr/leave-types/apply-pack', ['company_id' => $this->acme->id], $this->headersFor($this->hrOfficer))->assertOk();
        $this->assertNull($this->inTenant(fn () => $type->refresh()->paid));
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

        // National ID: 7 to 9 digits is only a hint (no authoritative source): another value is kept, with a warning.
        $this->putJson($url, ['identifiers' => [['type' => 'national_id', 'value' => '1234567']]], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('meta.warnings', []);
        $this->putJson($url, ['identifiers' => [['type' => 'national_id', 'value' => 'MN1234567890']]], $this->headersFor($this->hrOfficer))->assertOk()
            ->assertJsonPath('meta.warnings.0.code', 'identifier_format_unusual')->assertJsonPath('meta.warnings.0.field', 'identifiers.0.value');
        $this->assertSame(['MN1234567890'], $this->inTenant(fn () => EmployeeIdentifier::query()->where('employee_id', $employee->id)->where('type', 'national_id')->get()->map(fn ($i) => $i->value)->all()));
    }
}
