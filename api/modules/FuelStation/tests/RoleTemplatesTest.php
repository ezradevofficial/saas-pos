<?php

namespace Modules\FuelStation\Tests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\FuelStation\Tests\Concerns\BuildsFuel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FUEL-32, RBAC-03: the forecourt role templates hold what they should and no more.
class RoleTemplatesTest extends TestCase
{
    use BuildsFuel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFuel();
    }

    private function can($user, string $permission): bool
    {
        return $this->inTenant(fn () => app(ScopeResolver::class)->can($user, $permission, Scope::location($this->locationA->id)));
    }

    public function test_an_attendant_sells_opens_and_closes_but_decides_nothing(): void
    {
        foreach (['fuel.sale.create', 'fuel.shift.open', 'fuel.shift.close', 'fuel.voucher.redeem', 'fuel.dip.record', 'pos.till.sign_in'] as $permission) {
            $this->assertTrue($this->can($this->attendant, $permission), $permission);
        }

        foreach (['fuel.variance.approve', 'fuel.meter.approve_reset', 'fuel.meter.request_reset', 'fuel.delivery.record', 'fuel.price.manage', 'fuel.voucher.manage', 'fuel.report.view', 'fuel.variance.view'] as $permission) {
            $this->assertFalse($this->can($this->attendant, $permission), $permission);
        }
    }

    public function test_a_supervisor_runs_the_floor_but_cannot_approve_or_price(): void
    {
        foreach (['fuel.delivery.record', 'fuel.meter.request_reset', 'fuel.dip.record', 'fuel.variance.view', 'fuel.report.view', 'fuel.shift.view'] as $permission) {
            $this->assertTrue($this->can($this->supervisor, $permission), $permission);
        }

        foreach (['fuel.variance.approve', 'fuel.meter.approve_reset', 'fuel.price.manage', 'fuel.setup.manage', 'fuel.voucher.manage'] as $permission) {
            $this->assertFalse($this->can($this->supervisor, $permission), $permission);
        }
    }

    public function test_a_station_manager_approves_prices_and_keeps_the_fleet_but_not_the_company_settings(): void
    {
        foreach (['fuel.variance.approve', 'fuel.meter.approve_reset', 'fuel.price.manage', 'fuel.voucher.manage', 'fuel.vehicle.manage', 'fuel.setup.manage', 'fuel.settings.view'] as $permission) {
            $this->assertTrue($this->can($this->manager, $permission), $permission);
        }

        $this->assertFalse($this->can($this->manager, 'fuel.settings.edit'));
    }

    public function test_the_admin_configures_and_reads_but_never_approves_and_the_auditor_only_reads(): void
    {
        $admin = $this->userWith('admin', Scope::company($this->acme->id));
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $company = fn ($user, string $permission) => $this->inTenant(fn () => app(ScopeResolver::class)->can($user, $permission, Scope::company($this->acme->id)));

        foreach (['fuel.settings.edit', 'fuel.setup.manage', 'fuel.price.manage', 'fuel.report.view', 'fuel.variance.view'] as $permission) {
            $this->assertTrue($company($admin, $permission), $permission);
        }

        foreach (['fuel.variance.approve', 'fuel.meter.approve_reset', 'fuel.sale.create'] as $permission) {
            $this->assertFalse($company($admin, $permission), $permission);
        }

        $this->assertTrue($company($auditor, 'fuel.report.view'));
        $this->assertFalse($company($auditor, 'fuel.price.manage'));
    }
}
