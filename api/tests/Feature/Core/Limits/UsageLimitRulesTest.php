<?php

namespace Tests\Feature\Core\Limits;

use App\Core\Limits\Models\Overage;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\Limits\UsageNotices;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\BuildsUsageLimits;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LIM-03, LIM-04: the rules of the window and the guard: no escape by
 * archiving down for a moment, one reopened window, UTC calendar days,
 * tills that worked before, the lock against concurrent adds, notices only
 * after commit, the run's own time, and what a non-Owner is told.
 */
class UsageLimitRulesTest extends TestCase
{
    use BuildsOrganisation, BuildsUsageLimits, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
        $this->setUpLimits();
    }

    private function openOverage(string $unit): ?Overage
    {
        return $this->inTenant(fn () => Overage::query()->open()->where('unit', $unit)->first());
    }

    /** @return list<string> */
    private function inbox(): array
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->owner->id)->where('event_type', 'like', 'core.usage_limit.%')
            ->orderBy('created_at')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_archiving_down_adding_one_and_restoring_all_is_refused(): void
    {
        // Allowed 2 outlets, 4 in use, past the window.
        $this->travelTo(CarbonImmutable::parse('2026-10-12T06:00:00Z'));
        $this->allow([Units::OUTLET => 2]);
        [$c, $d] = $this->inTenant(fn () => [$this->location($this->branchA, 'Outlet C'), $this->location($this->branchA, 'Outlet D')]);
        $this->meter();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T06:00:00Z'));

        // Archive three: 1 in use, one more fits.
        foreach ([$this->locationB, $c, $d] as $outlet) {
            $this->postJson("/api/v1/locations/{$outlet->id}/archive", [], $this->headersFor())->assertOk();
        }
        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'Outlet E', 'type' => 'outlet'], $this->headersFor())->assertCreated();

        // 2 of 2 now: restoring any of them would go over, and the window is still past.
        foreach ([$this->locationB, $c, $d] as $outlet) {
            $this->postJson("/api/v1/locations/{$outlet->id}/restore", [], $this->headersFor())->assertStatus(402);
        }
        $this->assertSame(2, $this->inTenant(fn () => Location::query()->where('type', 'outlet')->whereNull('archived_at')->count()));
        $this->assertNotNull($this->openOverage(Units::OUTLET));
    }

    public function test_an_overage_reopened_within_a_window_of_closing_keeps_its_start(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T06:00:00Z'));
        $this->allow([Units::DEVICE => 2]);
        $devices = $this->devices(3);
        $this->meter();
        $started = $this->openOverage(Units::DEVICE)->started_at;

        // Day 3: back within the plan, closed by the meter. Day 4: over again.
        $this->travelTo(CarbonImmutable::parse('2026-10-15T06:00:00Z'));
        $this->inTenant(fn () => $devices[0]->forceFill(['status' => Device::STATUS_SUSPENDED])->save());
        $this->meter();
        $this->assertNull($this->openOverage(Units::DEVICE));
        $this->travelTo(CarbonImmutable::parse('2026-10-16T06:00:00Z'));
        $this->inTenant(fn () => $devices[0]->forceFill(['status' => Device::STATUS_ACTIVE])->save());
        $this->meter();

        $reopened = $this->openOverage(Units::DEVICE);
        $this->assertTrue($started->equalTo($reopened->started_at));
        $this->assertSame(4, $reopened->reminders_sent);

        // Day 7 of the original window: adding is refused.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T00:00:00Z'));
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 9'], $this->headersFor())->assertStatus(402);
    }

    public function test_days_are_utc_calendar_days_for_reminders_and_the_block(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T06:00:00Z'));
        $this->allow([Units::DEVICE => 2]);
        $this->devices(3);
        $this->meter();

        // Day 1, a second earlier in the day than the opening run: the reminder is due.
        $this->travelTo(CarbonImmutable::parse('2026-10-13T05:59:59Z'));
        $this->meter();
        $this->assertSame([UsageNotices::EXCEEDED, UsageNotices::REMINDER], $this->inbox());
        $this->assertSame(6, $this->openOverage(Units::DEVICE)->daysLeft(now()));

        // Day 7, a second earlier than the opening run: blocked, by the meter and the guard alike.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T05:59:59Z'));
        $overage = $this->openOverage(Units::DEVICE);
        $this->assertTrue($overage->isBlocking(now()));
        $this->assertSame('2026-10-19T00:00:00+00:00', $overage->blocksAt()->toIso8601String());
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())->assertStatus(402);
        $this->meter();
        $this->assertSame(UsageNotices::BLOCKED, last($this->inbox()));
    }

    public function test_a_till_that_worked_before_is_paired_again_and_resumed_while_blocked(): void
    {
        $devices = $this->blockDevices();
        $till = $devices[0];

        // Unpaired (paired_at cleared), it keeps first_paired_at and gets a code and pairs again.
        $this->postJson("/api/v1/devices/{$till->id}/unpair", [], $this->headersFor())->assertOk();
        $this->devices(1); // Back over: 3 of 2.
        $this->assertNotNull($this->inTenant(fn () => $till->fresh()->first_paired_at));
        $code = $this->postJson("/api/v1/devices/{$till->id}/pairing-code", [], $this->headersFor())->assertOk()->json('code');
        $this->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => 'Front till'])->assertOk();

        // A brand-new device is still refused, at the code and at pairing.
        $new = $this->inTenant(fn () => Device::create(['location_id' => $this->locationA->id, 'name' => 'New till']));
        $this->postJson("/api/v1/devices/{$new->id}/pairing-code", [], $this->headersFor())->assertStatus(402);

        // A first pairing records first_paired_at.
        $this->allow([Units::DEVICE => 10]);
        $code = $this->postJson("/api/v1/devices/{$new->id}/pairing-code", [], $this->headersFor())->assertOk()->json('code');
        $this->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => 'New till'])->assertOk();
        $this->assertNotNull($this->inTenant(fn () => $new->fresh()->first_paired_at));
    }

    public function test_the_guard_runs_inside_the_adding_transaction_under_a_per_unit_lock(): void
    {
        $limits = fn () => app(UsageLimits::class);

        $this->inTenant(function () use ($limits) {
            $held = fn () => (int) DB::selectOne("select count(*) as n from pg_locks where locktype = 'advisory' and pid = pg_backend_pid()")->n;

            DB::transaction(function () use ($limits, $held) {
                $before = $held();
                $limits()->ensureCanAdd(Units::DEVICE);
                $this->assertSame($before + 1, $held(), 'the per-tenant, per-unit lock is taken');
                $limits()->ensureCanAdd(Units::OUTLET);
                $this->assertSame($before + 2, $held(), 'another unit has its own lock');
            });
        });
    }

    public function test_notices_are_sent_only_once_the_meter_commits(): void
    {
        $this->allow([Units::DEVICE => 2]);
        $this->devices(3);

        $this->inTenant(fn () => DB::transaction(function () {
            app(UsageLimits::class)->meter(CarbonImmutable::now());
            $this->assertSame(0, InAppNotification::query()->count());
        }));

        $this->assertSame([UsageNotices::EXCEEDED], $this->inbox());
    }

    public function test_notices_use_the_runs_time(): void
    {
        $this->allow([Units::DEVICE => 2]);
        $this->devices(3);
        $this->meter(CarbonImmutable::parse('2026-12-01T06:00:00Z'));
        $this->meter(CarbonImmutable::parse('2026-12-04T06:00:00Z'));

        $reminder = $this->inTenant(fn () => InAppNotification::query()->where('event_type', UsageNotices::REMINDER)->firstOrFail());
        $this->assertSame('4 days left to upgrade for your POS devices', $reminder->subject);
    }

    public function test_a_non_owner_is_told_only_the_unit_and_to_ask_an_owner(): void
    {
        $this->blockDevices();
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $refused = $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor($manager))->assertStatus(402);
        $this->assertSame(['message', 'code', 'unit'], array_keys($refused->json()));
        $this->assertSame('device', $refused->json('unit'));
        $this->assertSame('Your plan’s limit for POS devices is reached. Ask an Owner to upgrade the plan to add more.', $refused->json('message'));

        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor($manager) + ['Accept-Language' => 'fr'])
            ->assertStatus(402)
            ->assertJsonPath('message', 'La limite de votre abonnement pour les appareils de caisse est atteinte. Demandez à un propriétaire de mettre à niveau l’abonnement pour en ajouter.');

        // The Owner gets the detail.
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())
            ->assertStatus(402)->assertJsonPath('allowed', 2)->assertJsonPath('upgrade_url', '/settings/subscription');
    }
}
