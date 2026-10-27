<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\Scope;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Models\NightAuditRun;
use Modules\Hotel\Models\Property;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\Properties;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-30, HOT-31: the night audit posts the night once, flags no-shows and overstays, rolls the business date and reports occupancy.
class NightAuditTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    private function businessDate(): string
    {
        return $this->inTenant(fn () => app(Properties::class)->businessDate($this->branchA->id));
    }

    private function roomLines(string $stayId): int
    {
        return $this->inTenant(fn () => FolioLine::query()->where('kind', 'room')->whereIn('folio_id', Folio::query()->where('stay_id', $stayId)->select('id'))->count());
    }

    public function test_the_audit_posts_each_night_once_rolls_the_date_and_is_idempotent_per_business_date(): void
    {
        $stay = $this->checkedIn(3, '101');
        $first = $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated()->json('data');
        $this->assertSame($this->day(0), $first['business_date']);
        $this->assertSame([1, 3, 1], [$first['summary']['rooms_sold'], $first['summary']['rooms_available'], $first['summary']['arrivals']]);
        $this->assertSame(33.3, $first['summary']['occupancy_percent']);
        $this->assertSame(['net_minor' => 888889, 'gross_minor' => 1000000, 'tax_minor' => 111111, 'adr_minor' => 888889, 'revpar_minor' => 296296], $first['summary']['revenue']['KES']);
        $this->assertSame($this->day(1), $this->businessDate());
        $this->assertSame(1, $this->roomLines($stay['id']));

        // The same date again (a repeat or a scheduler that lost the answer) changes nothing and answers the same audit.
        $again = $this->postJson('/api/v1/hotel/night-audits', ['branch_id' => $this->branchA->id, 'business_date' => $this->day(0)], $this->headersFor())->assertOk()->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame([1, $this->day(1)], [$this->roomLines($stay['id']), $this->businessDate()]);

        // Posting the same night by hand twice (the line's source key) also adds nothing.
        $this->inTenant(function () use ($stay) {
            $folio = Folio::query()->where('stay_id', $stay['id'])->first();
            $line = FolioLine::query()->where('folio_id', $folio->id)->where('kind', 'room')->first();
            $same = app(Folios::class)->post($folio, ['kind' => 'room', 'description' => 'dup', 'amount_minor' => 5, 'source' => $line->source, 'source_id' => $line->source_id]);
            $this->assertSame($line->id, $same->id);
        });
        $this->assertSame(1, $this->roomLines($stay['id']));

        $this->postJson('/api/v1/hotel/night-audits', ['branch_id' => $this->branchA->id, 'business_date' => $this->day(5)], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'audit_date_future');
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated();
        $this->assertSame(2, $this->roomLines($stay['id']));
        $this->getJson('/api/v1/hotel/night-audits?branch='.$this->branchA->id, $this->headersFor())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.business_date', $this->day(2));
    }

    public function test_no_shows_are_flagged_and_charged_and_overstays_are_listed_and_charged(): void
    {
        $arrived = $this->checkedIn(1, '101');
        $noShow = $this->book(2)['stays'][0]['id'];
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated();
        $this->assertSame('no_show', $this->inTenant(fn () => Stay::find($noShow)->status));
        $fee = $this->inTenant(fn () => FolioLine::query()->whereIn('folio_id', Folio::query()->where('stay_id', $noShow)->select('id'))->first());
        $this->assertSame(['fee', 1000000], [$fee->kind, $fee->amount_minor]);

        // The guest booked for one night is still in the room the next night: charged at the last rate, flagged.
        $audit = $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated()->json('data');
        $this->assertSame($arrived['id'], $audit['summary']['overstays'][0]['stay_id']);
        $this->assertSame(2, $this->roomLines($arrived['id']));
        $this->assertSame(['overstay'], $this->inTenant(fn () => FolioLine::query()->where('kind', 'room')->whereIn('folio_id', Folio::query()->where('stay_id', $arrived['id'])->select('id'))->orderBy('line_no')->get()->last()->flags));
    }

    public function test_the_audit_creates_the_next_days_cleaning_work(): void
    {
        $this->checkedIn(4, '101');
        $leaving = $this->checkedIn(1, '102');
        $this->postJson('/api/v1/hotel/stays/'.$leaving['id'].'/check-out', [], $this->headersFor())->assertOk();
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated();
        $tasks = $this->inTenant(fn () => HousekeepingTask::query()->where('business_date', $this->day(1))->pluck('kind', 'room_id')->all());
        $this->assertSame('stayover', $tasks[$this->rooms['101']->id]);
        $this->assertSame('checkout_clean', $tasks[$this->rooms['102']->id]);
    }

    public function test_the_schedule_audits_only_what_is_due_and_catches_up_a_day_at_a_time(): void
    {
        $this->checkedIn(5, '101');
        $this->inTenant(fn () => Settings::query()->where('company_id', $this->acme->id)->update(['night_audit_time' => '03:00']));
        $this->artisan('hotel:night-audit')->assertSuccessful();
        $this->assertSame($this->day(0), $this->businessDate(), 'the day is not over yet');

        // The server was down for two days: the property's business date is two days behind.
        $this->inTenant(fn () => Property::query()->where('branch_id', $this->branchA->id)->update(['business_date' => $this->day(-2)]));
        $this->artisan('hotel:night-audit')->assertSuccessful();
        $this->assertSame($this->day(0), $this->businessDate());
        $this->assertSame(2, $this->inTenant(fn () => NightAuditRun::query()->count()));
        // Run again: nothing is due, nothing changes.
        $this->artisan('hotel:night-audit')->assertSuccessful();
        $this->assertSame(2, $this->inTenant(fn () => NightAuditRun::query()->count()));
    }

    public function test_the_audit_is_for_those_who_hold_the_permission_at_the_property(): void
    {
        $auditor = $this->userWith('night_auditor', Scope::branch($this->branchA->id));
        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor($receptionist))->assertForbidden();
        $this->postJson('/api/v1/hotel/night-audits', ['branch_id' => $this->branchB->id, 'business_date' => $this->day(0), 'close_early' => true], $this->headersFor($auditor))->assertForbidden();
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor($auditor))->assertCreated();
    }

    public function test_the_occupancy_report_uses_audits_for_past_days_and_the_stays_for_the_future(): void
    {
        $this->checkedIn(2, '101');
        $this->book(2, ['arrival_on' => $this->day(1), 'departure_on' => $this->day(3)]);
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated();
        $report = $this->getJson('/api/v1/hotel/reports/occupancy?branch_id='.$this->branchA->id.'&from='.$this->day(0).'&to='.$this->day(2), $this->headersFor())->assertOk()->json('data');
        $this->assertSame(['audit', 'live', 'forecast'], array_column($report['days'], 'source'));
        $this->assertSame([1, 2, 1], array_column($report['days'], 'rooms_sold'));
        $this->assertSame(3, $report['days'][0]['rooms_available']);
        $this->assertSame(4, $report['totals']['rooms_sold']);
        $this->assertSame(9, $report['totals']['rooms_available']);
        $this->assertArrayHasKey('KES', $report['totals']['revenue']);

        $this->getJson('/api/v1/hotel/reports/arrivals?branch='.$this->branchA->id.'&date='.$this->day(1), $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/hotel/reports/in-house?branch='.$this->branchA->id, $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/hotel/reports/departures?branch='.$this->branchA->id.'&date='.$this->day(2), $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/hotel/reports/in-house?branch='.$this->branchA->id.'&format=csv', $this->headersFor())->assertOk();
    }
}
