<?php

namespace Tests\Feature\Core\Limits;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Limits\EmployeeCount;
use App\Core\Limits\Models\Overage;
use App\Core\Limits\Models\UsageMeter;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\Limits\UsageNotices;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\BuildsUsageLimits;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

/**
 * LIM-01 metering, LIM-02 the over-limit warning with the extra cost,
 * LIM-03 the 7-day window (daily reminders, then only adding more is
 * refused, closing when back within the plan), the Owner's usage endpoint,
 * other tenants (TEN-01) and the audit of overages (AUD-01).
 */
class UsageLimitsTest extends TestCase
{
    use BuildsOrganisation, BuildsUsageLimits, RefreshTenantDatabase, WithoutOwnerConnection;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
        $this->setUpLimits();
    }

    /** @return array<string, array{count: int, allowed: ?int}> the owner's tenant's meter rows of $day, by unit */
    private function meters(string $day): array
    {
        return $this->inTenant(fn () => UsageMeter::query()->whereDate('day', $day)->get()
            ->mapWithKeys(fn (UsageMeter $m) => [$m->unit => ['count' => $m->count, 'allowed' => $m->allowed]])->sortKeys()->all());
    }

    /** @return list<string> event types sent in the app to $user, oldest first */
    private function inbox(User $user): array
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('user_id', $user->id)->where('event_type', 'like', 'core.usage_limit.%')
            ->orderBy('created_at')->orderBy('id')->pluck('event_type')->all());
    }

    private function openOverage(string $unit): ?Overage
    {
        return $this->inTenant(fn () => Overage::query()->open()->where('unit', $unit)->first());
    }

    public function test_the_daily_meter_counts_active_outlets_devices_users_and_employees_through_due_tenants(): void
    {
        $other = $this->otherTenant();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T03:00:00Z'));

        $this->inTenant(function () {
            // Not outlets: a warehouse, and an archived outlet.
            $this->branchA->locations()->create(['name' => 'Back store', 'type' => 'warehouse']);
            $this->branchA->locations()->create(['name' => 'Closed outlet', 'type' => 'outlet'])->forceFill(['archived_at' => now()])->save();
            // Users: the owner and one active colleague count; pending and deactivated do not.
            $this->colleague($this->owner);
            $this->colleague($this->owner, ['status' => User::STATUS_PENDING]);
            $this->colleague($this->owner, ['status' => User::STATUS_DEACTIVATED]);
        });
        $this->devices(2);
        $this->devices(1, Device::STATUS_PENDING);
        $this->devices(1, Device::STATUS_SUSPENDED);
        $this->devices(1, Device::STATUS_UNPAIRED);
        $this->allow([Units::DEVICE => 4]);

        // The scheduler and the workers never need the owner's credentials (ADR 002).
        $this->withoutOwnerConnection();
        $this->artisan('usage:meter')->assertSuccessful();

        $this->assertSame([
            'device' => ['count' => 2, 'allowed' => 4],
            'employee' => ['count' => 0, 'allowed' => null],
            'outlet' => ['count' => 2, 'allowed' => null],
            'user' => ['count' => 2, 'allowed' => null],
        ], $this->meters('2026-10-12'));

        // The other tenant was metered in its own context: its own counts, unseen from here.
        $this->assertSame(1, $this->asTenant($other['user']->tenant_id, fn () => UsageMeter::query()->where('unit', 'outlet')->value('count')));
        $this->assertSame(4, $this->inTenant(fn () => UsageMeter::query()->count()));

        // A second run the same day updates the day; the next day adds a day (history kept).
        $this->devices(1);
        $this->artisan('usage:meter', ['--at' => '2026-10-12T15:00:00Z'])->assertSuccessful();
        $this->assertSame(3, $this->meters('2026-10-12')['device']['count']);
        $this->artisan('usage:meter', ['--at' => '2026-10-13T03:00:00Z'])->assertSuccessful();
        $this->assertSame(8, $this->inTenant(fn () => UsageMeter::query()->count()));

        // HR's count when it exists (the employee hook).
        $this->app->instance(EmployeeCount::class, new class implements EmployeeCount
        {
            public function count(): int
            {
                return 5;
            }
        });
        $this->meter(CarbonImmutable::parse('2026-10-14T03:00:00Z'));
        $this->assertSame(5, $this->meters('2026-10-14')['employee']['count']);
    }

    public function test_the_tenant_ids_come_from_a_locked_down_security_definer_function(): void
    {
        // ADR 002: usage:meter asks app_active_tenant_ids() (DueTenants::active) on the runtime connection.
        $fn = DB::selectOne(<<<'SQL'
            select p.prosecdef, p.proretset, p.proconfig::text as config, pg_get_userbyid(p.proowner) as owner,
                   format_type(p.prorettype, null) as returns, has_function_privilege('public', p.oid, 'execute') as public_execute,
                   has_function_privilege(current_user, p.oid, 'execute') as runtime_execute
            from pg_proc p join pg_namespace n on n.oid = p.pronamespace
            where n.nspname = 'public' and p.proname = 'app_active_tenant_ids'
            SQL);

        $this->assertTrue($fn->prosecdef && $fn->proretset && $fn->runtime_execute);
        $this->assertFalse($fn->public_execute);
        $this->assertSame(['uuid', config('database.connections.pgsql_owner.username')], [$fn->returns, $fn->owner]);
        $this->assertStringContainsString('search_path=pg_catalog, public', $fn->config);
        $this->assertSame('app', DB::selectOne('select current_user as name')->name);
        $this->assertFalse((bool) DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user')->rolbypassrls);
    }

    public function test_suspended_tenants_are_not_metered_and_the_history_cannot_be_deleted(): void
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => Tenant::query()->firstOrFail()->forceFill(['status' => 'suspended'])->save());

        $this->artisan('usage:meter')->assertSuccessful();

        $this->assertSame(4, $this->inTenant(fn () => UsageMeter::query()->count()));
        $this->assertSame(0, $this->asTenant($other['user']->tenant_id, fn () => UsageMeter::query()->count()));

        // TEN-06: the runtime role may not delete the history.
        $this->expectExceptionMessage('permission denied');
        $this->inTenant(fn () => DB::table('usage_meters')->delete());
    }

    public function test_going_over_warns_the_owners_in_the_app_and_by_email_with_the_extra_cost_and_an_upgrade_link(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->allow([Units::DEVICE => 2]);
        $this->devices(3);

        $this->meter(CarbonImmutable::parse('2026-10-12T03:00:00Z'));

        $overage = $this->openOverage(Units::DEVICE);
        $this->assertNotNull($overage);
        $this->assertSame([2, 3], [$overage->allowed, $overage->count]);
        $this->assertSame('2026-10-12T03:00:00+00:00', $overage->started_at->toIso8601String());
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.usage_overage.create')->exists()));

        // The Owner, in the app and by email; not the branch manager.
        $this->assertSame([UsageNotices::EXCEEDED], $this->inbox($this->owner));
        $this->assertSame([], $this->inbox($manager));
        $note = $this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->owner->id)->firstOrFail());
        $this->assertSame('/settings/subscription', $note->link);
        // One extra device at the catalogue's KES 500.00 (POS includes 2 per outlet).
        $this->assertStringContainsString('3 POS devices', $note->body);
        $this->assertStringContainsString('KES 500.00 a month', $note->body);
        $this->assertTrue($this->inTenant(fn () => NotificationDelivery::query()->where('user_id', $this->owner->id)->where('channel', 'email')->where('event_type', UsageNotices::EXCEEDED)->exists()));

        // Units within the plan open nothing.
        $this->assertNull($this->openOverage(Units::OUTLET));
        $this->assertNull($this->openOverage(Units::USER));
    }

    public function test_reminders_come_daily_for_seven_days_then_adding_is_paused_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T03:00:00Z'));
        $this->allow([Units::DEVICE => 2]);
        $this->devices(3);
        $this->meter();
        $this->meter(); // Twice the same day: nothing more.

        $expected = [UsageNotices::EXCEEDED];
        for ($day = 1; $day <= 6; $day++) {
            $this->travelTo(CarbonImmutable::parse('2026-10-12T03:00:00Z')->addDays($day));
            $this->meter();
            $this->meter(CarbonImmutable::now()->addHours(5));
            $expected[] = UsageNotices::REMINDER;
            $this->assertSame($expected, $this->inbox($this->owner), "day {$day}");
            $this->assertSame(7 - $day, $this->openOverage(Units::DEVICE)->daysLeft(now()));
        }

        // Day 7: adding is paused, said once.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T03:00:00Z'));
        $this->meter();
        $this->travelTo(CarbonImmutable::parse('2026-10-20T03:00:00Z'));
        $this->meter();

        $this->assertSame([...$expected, UsageNotices::BLOCKED], $this->inbox($this->owner));
        $this->assertSame(6, $this->openOverage(Units::DEVICE)->reminders_sent);
    }

    public function test_before_day_seven_adding_still_works(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T06:00:00Z'));
        $this->allow([Units::DEVICE => 2]);
        $this->devices(3);
        $this->meter();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T05:59:00Z'));

        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())->assertCreated();
    }

    public function test_after_day_seven_adding_a_device_is_refused_with_the_upgrade_link_and_existing_ones_keep_working(): void
    {
        [$first] = $this->blockDevices();
        $pending = $this->devices(1, Device::STATUS_PENDING)[0];
        $suspended = $this->devices(1, Device::STATUS_SUSPENDED)[0];

        $refused = $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())
            ->assertStatus(402)
            ->assertJsonPath('code', 'usage_limit_reached')
            ->assertJsonPath('unit', 'device')
            ->assertJsonPath('allowed', 2)
            ->assertJsonPath('count', 3)
            ->assertJsonPath('upgrade_url', '/settings/subscription');
        $this->assertSame('Your plan includes 2 POS devices and you have 3. Upgrade your plan to add more. Everything already set up keeps working.', $refused->json('message'));

        $this->postJson("/api/v1/devices/{$pending->id}/pairing-code", [], $this->headersFor())->assertStatus(402);
        $this->postJson("/api/v1/devices/{$suspended->id}/resume", [], $this->headersFor())->assertStatus(402);
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor() + ['Accept-Language' => 'fr'])
            ->assertStatus(402)
            ->assertJsonPath('message', 'Votre abonnement comprend 2 appareils de caisse et vous en avez 3. Passez à un abonnement supérieur pour en ajouter. Tout ce qui est déjà en place continue de fonctionner.');

        // Existing devices keep working: rename, suspend; other units are not limited.
        $this->patchJson("/api/v1/devices/{$first->id}", ['name' => 'Front till'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/devices/{$first->id}/suspend", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'Outlet A2', 'type' => 'outlet'], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/invitations', ['name' => 'Neema', 'email' => 'neema@example.com', 'assignments' => [['role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationA->id]]], $this->headersFor())->assertCreated();

        // Suspending one brought the count to 2: adding one would go over again, still refused.
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())->assertStatus(402);
    }

    public function test_after_day_seven_adding_outlets_and_users_is_refused_but_other_locations_are_not(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T06:00:00Z'));
        $this->allow([Units::OUTLET => 1, Units::USER => 1]);
        [$office, $closed, $former] = $this->inTenant(function () {
            $office = $this->branchA->locations()->create(['name' => 'Office', 'type' => 'office']);
            $closed = $this->branchA->locations()->create(['name' => 'Closed outlet', 'type' => 'outlet']);
            $closed->forceFill(['archived_at' => now()])->save();
            $former = $this->colleague($this->owner, ['status' => User::STATUS_DEACTIVATED]);
            $this->colleague($this->owner);

            return [$office, $closed, $former];
        });
        $this->meter();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T06:00:00Z'));

        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'Outlet A2', 'type' => 'outlet'], $this->headersFor())
            ->assertStatus(402)->assertJsonPath('unit', 'outlet');
        $this->patchJson("/api/v1/locations/{$office->id}", ['type' => 'outlet'], $this->headersFor())->assertStatus(402);
        $this->postJson("/api/v1/locations/{$closed->id}/restore", [], $this->headersFor())->assertStatus(402);
        $this->postJson('/api/v1/invitations', ['name' => 'Neema', 'email' => 'neema@example.com', 'assignments' => [['role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationA->id]]], $this->headersFor())
            ->assertStatus(402)->assertJsonPath('unit', 'user');
        $this->postJson("/api/v1/users/{$former->id}/reactivate", [], $this->headersFor())->assertStatus(402);

        // Not an outlet: still allowed. Existing outlets and users keep working.
        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'Depot', 'type' => 'warehouse'], $this->headersFor())->assertCreated();
        $this->patchJson("/api/v1/locations/{$this->locationA->id}", ['name' => 'Outlet A renamed'], $this->headersFor())->assertOk();
        $this->getJson('/api/v1/users', $this->headersFor())->assertOk();
    }

    public function test_a_user_without_permission_is_refused_before_any_limit_is_consulted(): void
    {
        $this->blockDevices();
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor($cashier))->assertForbidden();
    }

    public function test_the_overage_closes_when_back_within_the_plan_and_adding_works_again(): void
    {
        $devices = $this->blockDevices();

        // Two unpaired: one more fits, so the guard closes the overage and lets it through.
        $this->postJson("/api/v1/devices/{$devices[0]->id}/unpair", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/devices/{$devices[1]->id}/unpair", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())->assertCreated();
        $closed = $this->inTenant(fn () => Overage::query()->where('unit', 'device')->firstOrFail());
        $this->assertSame(Overage::WITHIN_ALLOWANCE, $closed->close_reason);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.usage_overage.update')->exists()));

        // Over again: a new overage, a new window. Back within: the meter closes it.
        $this->devices(2);
        $this->meter();
        $this->assertNotNull($this->openOverage(Units::DEVICE));
        $this->inTenant(fn () => Device::query()->where('status', 'active')->limit(1)->get()->each(fn (Device $d) => $d->forceFill(['status' => 'unpaired'])->save()));
        $this->meter();
        $this->assertNull($this->openOverage(Units::DEVICE));
        $this->assertSame(2, $this->inTenant(fn () => Overage::query()->whereNotNull('closed_at')->count()));
    }

    public function test_an_upgrade_lifts_the_block_at_once(): void
    {
        $this->blockDevices();
        $this->allow([Units::DEVICE => 5]);

        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())->assertCreated();
        $this->assertNull($this->openOverage(Units::DEVICE));

        // No limit at all on the unit closes it too.
        $this->allow([Units::DEVICE => 2]);
        $this->devices(1);
        $this->meter();
        $this->allow([]);
        $this->meter();
        $this->assertSame(Overage::UNLIMITED, $this->inTenant(fn () => Overage::query()->latest('started_at')->firstOrFail())->close_reason);
    }

    public function test_the_owner_sees_usage_against_the_plan_and_others_are_refused(): void
    {
        $other = $this->otherTenant();
        $this->blockDevices();
        $this->allow([Units::USER => 5, Units::DEVICE => 2]);

        $usage = $this->getJson('/api/v1/usage', $this->headersFor())->assertOk()->json('data');
        $units = collect($usage['units'])->keyBy('unit');
        $this->assertSame(['unit' => 'device', 'count' => 3, 'allowed' => 2, 'over' => 1, 'state' => 'blocked'], array_intersect_key($units['device'], array_flip(['unit', 'count', 'allowed', 'over', 'state'])));
        $this->assertSame(0, $units['device']['overage']['days_left']);
        $this->assertSame(['amount_minor' => '50000', 'currency' => 'KES'], $units['device']['extra_cost']);
        $this->assertSame(['unlimited', 'within', 'unlimited'], [$units['outlet']['state'], $units['user']['state'], $units['employee']['state']]);
        $this->assertNull($units['user']['extra_cost']);
        $this->assertSame(['amount_minor' => '50000', 'currency' => 'KES'], $usage['extra_cost']);
        $this->assertSame('/settings/subscription', $usage['upgrade_url']);
        $this->assertSame(7, $usage['window_days']);

        // Me/permissions tells the UI who is an Owner.
        $this->assertTrue($this->getJson('/api/v1/me/permissions', $this->headersFor())->json('owner'));

        // Not an Owner: refused, even an Admin.
        $admin = $this->userWith('admin', Scope::tenant());
        $this->getJson('/api/v1/usage', $this->headersFor($admin))->assertForbidden();
        $this->assertFalse($this->getJson('/api/v1/me/permissions', $this->headersFor($admin))->json('owner'));
        $this->getJson('/api/v1/usage')->assertUnauthorized();

        // The other tenant's Owner sees only their own tenant, never ours.
        $theirs = $this->getJson('/api/v1/usage', $this->bearer($this->tokenFor($other['user'])))->assertOk()->json('data');
        $this->assertSame(['outlet' => 1, 'device' => 0, 'user' => 1, 'employee' => 0], collect($theirs['units'])->pluck('count', 'unit')->all());
        $this->assertNull($theirs['units'][1]['overage']);
        $this->assertSame(0, $this->asTenant($other['user']->tenant_id, fn () => Overage::query()->count()));
    }

    public function test_the_other_tenants_limits_never_apply_here(): void
    {
        $other = $this->otherTenant();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T06:00:00Z'));
        $this->allow([Units::OUTLET => 0], $other['user']->tenant_id);
        $this->asTenant($other['user']->tenant_id, fn () => app(UsageLimits::class)->meter(CarbonImmutable::now()));
        $this->travelTo(CarbonImmutable::parse('2026-10-20T06:00:00Z'));

        // Their outlets are blocked; ours are not.
        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'Outlet A2', 'type' => 'outlet'], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/branches/{$other['branch']->id}/locations", ['name' => 'More', 'type' => 'outlet'], $this->bearer($this->tokenFor($other['user']), ['Accept' => 'application/json']))->assertStatus(402);
        $this->assertSame(0, $this->inTenant(fn () => Overage::query()->count()));
        $this->assertSame(0, $this->inTenant(fn () => Location::query()->where('name', 'More')->count()));
    }
}
