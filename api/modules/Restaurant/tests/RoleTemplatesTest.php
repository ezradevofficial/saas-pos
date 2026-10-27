<?php

namespace Modules\Restaurant\Tests;

use App\Core\Onboarding\BusinessTypes;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Restaurant\RestaurantServiceProvider;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-01, REST-19, RBAC-03, RBAC-08: the Waiter, Kitchen and Bartender
// templates, what the Branch Manager and Admin hold, and permissions that
// grant nothing while the module is off.
class RoleTemplatesTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    /** @return list<string> */
    private function granted(string $template): array
    {
        return $this->inTenant(fn () => $this->roles->get($template)->permissions()->where('module', 'restaurant')->orderBy('name')->pluck('name')->all());
    }

    public function test_the_catalogue_lists_the_modules_seventeen_permissions(): void
    {
        $names = [];

        foreach (RestaurantServiceProvider::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $names[] = "restaurant.{$resource}.{$action}";
            }
        }

        $this->assertCount(17, $names);
        $this->assertSame($names, array_values(array_intersect($names, $this->inTenant(fn () => Permission::where('module', 'restaurant')->pluck('name')->all()))));
    }

    public function test_the_waiter_works_tables_and_tabs_but_never_sets_up_voids_or_discounts(): void
    {
        $this->assertSame([
            'restaurant.floor.view', 'restaurant.modifier.view', 'restaurant.order.close', 'restaurant.order.create', 'restaurant.order.edit', 'restaurant.order.merge',
            'restaurant.order.send', 'restaurant.order.transfer', 'restaurant.order.view', 'restaurant.station.view', 'restaurant.ticket.update', 'restaurant.ticket.view',
        ], $this->granted('waiter'));

        $held = $this->inTenant(fn () => $this->roles->get('waiter')->permissions()->pluck('name')->all());
        foreach (['pos.sale.void', 'pos.sale.refund', 'pos.discount.give', 'pos.price.override'] as $never) {
            $this->assertNotContains($never, $held);
        }
    }

    public function test_the_kitchen_sees_and_moves_tickets_only(): void
    {
        $this->assertSame(['restaurant.floor.view', 'restaurant.station.view', 'restaurant.ticket.update', 'restaurant.ticket.view'], $this->granted('kitchen'));
        $this->assertSame([], $this->inTenant(fn () => $this->roles->get('kitchen')->permissions()->where('module', 'pos')->pluck('name')->all()));
    }

    public function test_the_bartender_is_a_waiter_with_a_shift_of_their_own(): void
    {
        $this->assertSame($this->granted('waiter'), $this->granted('bartender'));
        $this->assertSame(['pos.sale.create', 'pos.sale.print', 'pos.sale.view', 'pos.shift.close', 'pos.shift.open', 'pos.shift.view', 'pos.till.sign_in'],
            $this->inTenant(fn () => $this->roles->get('bartender')->permissions()->where('module', 'pos')->orderBy('name')->pluck('name')->all()));
    }

    public function test_the_branch_manager_holds_it_all_but_the_service_charge_and_the_admin_sets_up(): void
    {
        $manager = $this->granted('branch_manager');
        $this->assertCount(16, $manager);
        $this->assertNotContains('restaurant.settings.edit', $manager);

        $admin = $this->granted('admin');
        $this->assertContains('restaurant.settings.edit', $admin);
        $this->assertContains('restaurant.floor.manage', $admin);
        $this->assertContains('restaurant.report.view', $admin);
        $this->assertNotContains('restaurant.order.create', $admin, 'the Admin reads orders; the floor staff take them');
        $this->assertCount(17, $this->granted('owner'));
    }

    public function test_a_permission_grants_nothing_while_the_module_is_off(): void
    {
        $resolver = app(ScopeResolver::class);
        $this->assertTrue($this->inTenant(fn () => $resolver->can($this->waiter, 'restaurant.order.create', Scope::location($this->locationA->id))));

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('restaurant'));
        $this->assertFalse($this->inTenant(fn () => $resolver->can($this->waiter, 'restaurant.order.create', Scope::location($this->locationA->id))));
    }

    public function test_the_restaurant_and_bar_presets_offer_the_new_roles_and_sell_with_pos(): void
    {
        $types = collect(app(BusinessTypes::class)->all())->keyBy('key');
        $templates = array_column(app(RoleTemplates::class)->all(), 'key');

        $this->assertContains('kitchen', $types['restaurant']['role_templates']);
        $this->assertContains('bartender', $types['bar']['role_templates']);
        $this->assertSame([], array_diff([...$types['restaurant']['role_templates'], ...$types['bar']['role_templates']], $templates));
        $this->assertContains('pos', $types['restaurant']['modules']);
        $this->assertContains('pos', $types['bar']['modules']);

        foreach (['restaurant', 'bar', 'hotel'] as $key) {
            $this->assertContains('restaurant', $types[$key]['modules'], "{$key} suggests the Restaurant module");
        }
        $this->assertNotContains('restaurant', $types['retail']['modules']);
        $this->assertContains('restaurant', app(BusinessTypes::class)->moduleGroups()['sell']);
        $this->assertNotContains('restaurant', app(ModuleRegistry::class)->coveredBy(['pos']), 'a subscription to POS alone does not switch the Restaurant module on');
        $this->assertSame(['restaurant'], app(ModuleRegistry::class)->coveredBy(['restaurant']));
    }
}
