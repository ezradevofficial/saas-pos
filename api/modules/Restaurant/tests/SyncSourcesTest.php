<?php

namespace Modules\Restaurant\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\Models\Tenant;
use Modules\Restaurant\Models\Area;
use Modules\Restaurant\Models\ModifierGroup;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\Setting;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Sync\Sources\ModifierSource;
use Modules\Restaurant\Sync\Sources\OrderSource;
use Modules\Restaurant\Sync\Sources\SettingsSource;
use Modules\Restaurant\Sync\Sources\StationSource;
use Modules\Restaurant\Sync\Sources\TableSource;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04, REST-17: what the till pulls for the floor, the stations, the
// modifiers, the service charge and the open orders; served only while the
// module is active, and only for the till's own location.
class SyncSourcesTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    private function rows(object $source, $till = null): array
    {
        return $this->inTenant(fn () => $source->rows(DeviceScope::of($till ?? $this->till)));
    }

    public function test_a_waiters_row_on_the_till_carries_the_restaurant_permissions(): void
    {
        // Without the `restaurant.` till prefix the till hid "open order" from every waiter (REST-19).
        $staff = collect($this->getJson('/api/v1/sync/pull?entities[]=staff', $this->tillHeaders())->assertOk()->json('entities.staff.upserts'))->firstWhere('id', $this->waiter->id);

        $this->assertContains('restaurant.order.create', $staff['permissions']);
        $this->assertContains('restaurant.order.send', $staff['permissions']);
        $this->assertContains('van_sales.', app(SyncSources::class)->tillPermissionPrefixes());
    }

    public function test_the_floor_plan_lists_the_locations_tables_with_their_area(): void
    {
        $this->inTenant(function () {
            Area::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'location_id' => $this->locationB->id, 'name' => 'Hall B']);
            $this->t3->archive();
        });

        $rows = $this->rows(new TableSource);

        $this->assertSame(['T1', 'T2'], array_column($rows, 'name'));
        $this->assertSame(['Main hall', 4, 'square', 'free'], [$rows[0]['area_name'], $rows[0]['seats'], $rows[0]['shape'], $rows[0]['status']]);
        $this->assertSame($this->hall->id, $rows[0]['area_id']);
    }

    public function test_stations_carry_their_routed_categories(): void
    {
        $rows = collect($this->rows(new StationSource))->keyBy('name');

        $this->assertSame([true, [$this->food->id]], [$rows['Kitchen']['is_default'], $rows['Kitchen']['category_ids']]);
        $this->assertSame([false, 'bar', [$this->drinks->id]], [$rows['Bar']['is_default'], $rows['Bar']['kind'], $rows['Bar']['category_ids']]);
    }

    public function test_modifier_groups_leave_out_archived_ones_and_archived_modifiers(): void
    {
        $this->api('POST', 'restaurant/modifier-groups', ['company_id' => $this->acme->id, 'name' => 'Cooking', 'min_select' => 1, 'max_select' => 1, 'currency' => 'KES', 'item_ids' => [$this->burger->id],
            'modifiers' => [['name' => 'Rare', 'price_minor' => '0'], ['name' => 'Well done', 'price_minor' => '0']]])->assertCreated();
        $old = $this->api('POST', 'restaurant/modifier-groups', ['company_id' => $this->acme->id, 'name' => 'Old', 'min_select' => 0, 'max_select' => 1, 'currency' => 'KES'])->assertCreated()->json('data.id');
        $this->api('POST', "restaurant/modifier-groups/{$old}/archive")->assertOk();

        $rows = $this->rows(new ModifierSource);

        $this->assertCount(1, $rows);
        $this->assertSame(['Cooking', [$this->burger->id], ['Rare', 'Well done']], [$rows[0]['name'], $rows[0]['item_ids'], array_column($rows[0]['modifiers'], 'name')]);
        $this->assertSame('0', $rows[0]['modifiers'][0]['price_minor']);
        $this->assertSame(1, ModifierGroup::query()->whereNotNull('archived_at')->count());
    }

    public function test_settings_carry_the_service_charge_the_items_and_the_tills_mode(): void
    {
        $this->inTenant(function () {
            Tenant::query()->update(['settings' => ['pos_mode' => 'restaurant']]);
        });
        $empty = $this->rows(new SettingsSource)[0];
        $this->assertSame([$this->acme->id, 'restaurant', '0.0000', null], [$empty['id'], $empty['pos_mode'], $empty['service_charge_percent'], $empty['service_charge_item_id']]);

        $this->inTenant(fn () => Setting::create(['company_id' => $this->acme->id, 'service_charge_percent' => '12.5', 'service_charge_item_id' => $this->serviceItem->id, 'tip_item_id' => $this->tipItem->id, 'tip_presets' => [5, 10]]));
        $row = $this->rows(new SettingsSource)[0];

        $this->assertSame(['12.5000', $this->serviceItem->id, $this->tipItem->id, [5, 10]], [$row['service_charge_percent'], $row['service_charge_item_id'], $row['tip_item_id'], $row['tip_presets']]);
    }

    public function test_open_orders_of_the_location_come_with_lines_and_ticket_states_and_nothing_else(): void
    {
        $open = $this->orderRecord([$this->sent($this->orderLine())]);
        $closed = $this->orderRecord([$this->orderLine()], ['table_id' => $this->t2->id]);
        $this->store($open);
        $this->store($closed);
        $this->inTenant(fn () => Order::query()->whereKey($closed['id'])->update(['status' => 'closed']));
        [$tillB] = $this->pairedTill($this->locationB, 'Till B');

        $rows = $this->rows(new OrderSource);

        $this->assertSame([$open['id']], array_column($rows, 'id'));
        $this->assertArrayNotHasKey('settlements', $rows[0]);
        $this->assertSame(['new'], array_column($rows[0]['lines'], 'ticket_status'));
        $this->assertSame(['new', 'order'], [$rows[0]['tickets'][0]['status'], $rows[0]['tickets'][0]['kind']]);
        $this->assertSame([], $this->rows(new OrderSource, $tillB), 'another location\'s till is not sent these orders');
    }

    public function test_the_entities_are_offered_to_a_till_only_while_the_module_is_active(): void
    {
        $keys = fn () => array_column($this->getJson('/api/v1/sync/bootstrap', $this->tillHeaders())->assertOk()->json('entities'), 'key');

        $this->assertEqualsCanonicalizing(['restaurant_tables', 'restaurant_stations', 'restaurant_modifiers', 'restaurant_settings', 'restaurant_orders'], array_values(array_filter($keys(), fn ($k) => str_starts_with($k, 'restaurant_'))));

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('restaurant'));
        $this->assertSame([], array_values(array_filter($keys(), fn ($k) => str_starts_with($k, 'restaurant_'))));
        $this->assertSame(3, $this->inTenant(fn () => Table::query()->count()), 'nothing is deleted when the module goes off');
    }
}
