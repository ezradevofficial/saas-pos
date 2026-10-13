<?php

namespace Tests\Feature\Core\Limits;

use App\Core\Limits\PlanAllowances;
use App\Core\Limits\SelectionAllowances;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Tenancy\Rls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LIM-01: until billing binds its own, the allowances come from the
 * onboarding or trial selection (ONB-03, ONB-04), read under row-level
 * security (TEN-01); unlimited without one. Where onboarding is not
 * installed, the test creates its table (the columns read) and drops it.
 */
class SelectionAllowancesTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function onboardingTable(): void
    {
        if (Schema::hasTable('onboarding_states')) {
            return;
        }

        $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
        $owner->statement("create table onboarding_states (id uuid primary key, tenant_id uuid not null default nullif(current_setting('app.tenant_id', true), '')::uuid, selection jsonb, created_at timestamptz, updated_at timestamptz)");
        $owner->statement('alter table onboarding_states enable row level security');
        $owner->statement('alter table onboarding_states force row level security');
        $owner->statement(sprintf('create policy %s on onboarding_states using (tenant_id = %2$s) with check (tenant_id = %2$s)', Rls::POLICY, Rls::TENANT_EXPRESSION));

        $this->beforeApplicationDestroyed(fn () => DB::connection(SyncPermissions::OWNER_CONNECTION)->statement('drop table if exists onboarding_states'));
    }

    private function select(string $tenantId, ?array $selection): void
    {
        $this->asTenant($tenantId, fn () => DB::table('onboarding_states')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'selection' => $selection === null ? null : json_encode($selection)]));
    }

    public function test_it_is_the_default_binding(): void
    {
        $this->assertInstanceOf(SelectionAllowances::class, app(PlanAllowances::class));
    }

    public function test_without_a_selection_everything_is_unlimited(): void
    {
        $this->assertSame([], app(SelectionAllowances::class)->for($this->owner->tenant_id)->limits);

        $this->onboardingTable();
        $this->select($this->owner->tenant_id, null);

        $allowances = app(SelectionAllowances::class)->for($this->owner->tenant_id);
        $this->assertSame([], $allowances->limits);
        $this->assertNull($allowances->plan);
    }

    public function test_the_trial_selection_sets_outlets_devices_and_employees(): void
    {
        $this->onboardingTable();
        $other = $this->otherTenant()['user']->tenant_id;
        $this->select($this->owner->tenant_id, ['country' => 'KE', 'modules' => ['pos', 'inventory', 'payroll'], 'quantities' => ['outlet' => 2, 'device' => 5, 'employee' => 12], 'cycle' => 'monthly', 'bundle' => null]);
        $this->select($other, ['country' => 'CD', 'modules' => [], 'quantities' => ['outlet' => 3], 'cycle' => 'annual', 'bundle' => 'starter']);

        $ours = app(SelectionAllowances::class)->for($this->owner->tenant_id);
        $this->assertSame(['outlet' => 2, 'device' => 5, 'user' => null, 'employee' => 12], $ours->limits);
        $this->assertSame(['KE', ['pos', 'inventory', 'payroll']], [$ours->plan->country, $ours->plan->modules]);

        // A bundle's modules count; devices are at least what POS includes (2 per outlet); no HR: employees unlimited.
        $theirs = app(SelectionAllowances::class)->for($other);
        $this->assertSame(['outlet' => 3, 'device' => 6, 'user' => null, 'employee' => null], $theirs->limits);
        $this->assertSame('starter', $theirs->plan->bundle);
    }

    public function test_modules_sold_per_company_only_leave_outlets_and_devices_unlimited(): void
    {
        $this->onboardingTable();
        $this->select($this->owner->tenant_id, ['country' => 'KE', 'modules' => ['crm'], 'quantities' => [], 'cycle' => 'monthly']);

        $this->assertSame(['outlet' => null, 'device' => null, 'user' => null, 'employee' => null], app(SelectionAllowances::class)->for($this->owner->tenant_id)->limits);
    }
}
