<?php

namespace Modules\VanSales\Tests;

use App\Core\Onboarding\BusinessTypes;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Sync\DeviceScope;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Sync\Sources\AccountsSource;
use Modules\VanSales\Sync\Sources\LoadsSource;
use Modules\VanSales\Sync\Sources\RoutesSource;
use Modules\VanSales\Sync\Sources\RunsSource;
use Modules\VanSales\Sync\Sources\VanSource;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Modules\VanSales\VanSalesServiceProvider;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// VAN-01, VAN-26, VAN-27, RBAC-03, RBAC-08: the Van rep and Route supervisor
// templates, permissions that grant nothing while the module is off, and
// what the van's device pulls (only for its own van).
class RolesAndSyncTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpVanSales();
    }

    /** @return list<string> */
    private function granted(string $template): array
    {
        return $this->inTenant(fn () => $this->roles->get($template)->permissions()->where('module', 'van_sales')->orderBy('name')->pluck('name')->all());
    }

    private function rows(object $source, $device = null): array
    {
        return $this->inTenant(fn () => $source->rows(DeviceScope::of($device ?? $this->vanDevice)));
    }

    public function test_the_catalogue_lists_the_modules_permissions(): void
    {
        $names = [];

        foreach (VanSalesServiceProvider::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $names[] = "van_sales.{$resource}.{$action}";
            }
        }

        $this->assertCount(23, $names);
        $this->assertEqualsCanonicalizing($names, $this->inTenant(fn () => Permission::where('module', 'van_sales')->pluck('name')->all()));
    }

    public function test_the_rep_works_the_day_but_never_sets_up_decides_or_voids(): void
    {
        $this->assertSame([
            'van_sales.collection.record', 'van_sales.collection.view', 'van_sales.load.confirm', 'van_sales.load.view', 'van_sales.reconciliation.submit',
            'van_sales.reconciliation.view', 'van_sales.route.view', 'van_sales.run.start', 'van_sales.run.view', 'van_sales.van.view', 'van_sales.visit.record',
        ], $this->granted('van_rep'));

        $held = $this->inTenant(fn () => $this->roles->get('van_rep')->permissions()->pluck('name')->all());
        foreach (['pos.sale.void', 'pos.discount.give', 'pos.price.override', 'van_sales.reconciliation.approve', 'van_sales.visit.track', 'van_sales.credit.review'] as $never) {
            $this->assertNotContains($never, $held);
        }
    }

    public function test_the_supervisor_decides_but_never_counts_or_sells(): void
    {
        $held = $this->granted('route_supervisor');

        foreach (['van_sales.reconciliation.approve', 'van_sales.credit.review', 'van_sales.van.manage', 'van_sales.route.manage', 'van_sales.load.create', 'van_sales.visit.track', 'van_sales.unload.receive'] as $right) {
            $this->assertContains($right, $held);
        }

        foreach (['van_sales.load.release', 'van_sales.reconciliation.submit', 'van_sales.collection.record', 'van_sales.run.start', 'van_sales.visit.record', 'van_sales.settings.edit'] as $never) {
            $this->assertNotContains($never, $held);
        }
    }

    public function test_the_manager_and_admin_and_storekeeper(): void
    {
        $manager = $this->granted('branch_manager');
        $this->assertContains('van_sales.reconciliation.approve', $manager);
        $this->assertNotContains('van_sales.settings.edit', $manager);

        $admin = $this->granted('admin');
        foreach (['van_sales.van.manage', 'van_sales.route.manage', 'van_sales.settings.edit', 'van_sales.report.view', 'van_sales.credit.view'] as $right) {
            $this->assertContains($right, $admin);
        }
        $this->assertNotContains('van_sales.reconciliation.approve', $admin);

        $this->assertSame(['van_sales.load.release', 'van_sales.load.view', 'van_sales.unload.receive'], $this->granted('storekeeper'));
    }

    public function test_permissions_grant_nothing_while_the_module_is_off_and_roles_return_with_it(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('van_sales'));

        $this->assertFalse($this->inTenant(fn () => app(ScopeResolver::class)->can($this->rep, 'van_sales.run.view', Scope::location($this->vanLocation->id))));
        $this->api('GET', 'van-sales/vans', [], $this->supervisor)->assertForbidden()->assertJsonPath('code', 'module_inactive');
        $this->vsSync(['runs' => [$this->runRecord()]])->assertForbidden();

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('van_sales'));
        $this->assertTrue($this->inTenant(fn () => app(ScopeResolver::class)->can($this->rep, 'van_sales.run.view', Scope::location($this->vanLocation->id))));
        $this->api('GET', 'van-sales/vans', [], $this->supervisor)->assertOk();
    }

    public function test_the_van_sales_preset_suggests_the_module_and_its_roles(): void
    {
        $type = collect(app(BusinessTypes::class)->all())->firstWhere('key', 'van_sales');

        $this->assertContains('van_sales', $type['modules']);
        $this->assertContains('van_rep', $type['role_templates']);
        $this->assertContains('route_supervisor', $type['role_templates']);
        $this->assertSame('van_sales', $type['pos_mode']);
    }

    public function test_the_device_pulls_its_van_routes_customers_loads_and_open_run(): void
    {
        $run = $this->startRun();
        $load = $this->loadVan('10', $run);
        $this->sellOnVan($this->vanSale($run, 1, $this->shop, [$this->pay('credit', '112500')]))->assertOk();
        $this->vsSync(['visits' => [$this->visitRecord($run, $this->shop)]])->assertOk();

        $van = $this->rows(new VanSource);
        $this->assertSame(['Van 1', $this->rep->id, 'off', 30], [$van[0]['name'], $van[0]['rep_id'], $van[0]['gps_checkin'], $van[0]['gps_retention_days']]);

        $routes = $this->rows(new RoutesSource);
        $this->assertSame(['Daily route', [1, 2, 3, 4, 5, 6, 7], ['Corner shop', 'Kiosk']], [$routes[0]['name'], $routes[0]['visit_days'], array_column($routes[0]['stops'], 'customer_name')]);

        $accounts = collect($this->rows(new AccountsSource))->keyBy('id');
        $this->assertSame(['1000000', 'KES', [['currency' => 'KES', 'amount_minor' => '112500']]], [$accounts[$this->shop->id]['credit_limit_minor'], $accounts[$this->shop->id]['credit_limit_currency'], $accounts[$this->shop->id]['balances']]);
        $this->assertNull($accounts[$this->kiosk->id]['credit_limit_minor']);

        $loads = $this->rows(new LoadsSource);
        $this->assertSame([$load, 'confirmed', '10'], [$loads[0]['id'], $loads[0]['status'], $loads[0]['lines'][0]['qty_confirmed']]);

        $runs = $this->rows(new RunsSource);
        $this->assertSame([$run, 'open', null, 1], [$runs[0]['id'], $runs[0]['status'], $runs[0]['count'], count($runs[0]['visits'])]);
    }

    public function test_a_device_that_is_not_a_vans_pulls_nothing(): void
    {
        foreach ([new VanSource, new RoutesSource, new AccountsSource, new LoadsSource, new RunsSource] as $source) {
            $this->assertSame([], $this->rows($source, $this->till), $source->key());
        }

        // Nor may it upload: its place has no van.
        $this->vsSync(['runs' => [$this->runRecord()]], $this->tillToken)->assertStatus(422)->assertJsonPath('results.runs.0.code', 'van_unknown');
    }

    public function test_a_van_tied_to_another_device_is_not_served_to_a_second_device_at_its_location(): void
    {
        [$second] = $this->pairedTill($this->vanLocation, 'Second phone');

        $this->assertSame([], $this->rows(new VanSource, $second));
    }

    public function test_another_tenants_routes_and_customers_are_never_pulled(): void
    {
        $other = $this->otherVanTenant();
        $this->assertSame(0, $this->asTenant($other['user']->tenant_id, fn () => Route::query()->count()));
    }
}
