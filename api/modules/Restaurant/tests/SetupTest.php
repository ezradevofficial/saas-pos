<?php

namespace Modules\Restaurant\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\Restaurant\Models\Area;
use Modules\Restaurant\Models\Modifier;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\StationRoute;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-02, REST-07, REST-08, REST-13, REST-20, RBAC-04, TEN-06: the floor
// plan, stations and routing, modifier groups and the service charge are set
// up by people who hold the right at the place, audited, archived (never
// deleted) and invisible to other tenants.
class SetupTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    public function test_areas_and_tables_are_set_up_listed_and_archived(): void
    {
        $area = $this->api('POST', 'restaurant/areas', ['location_id' => $this->locationA->id, 'name' => 'Terrace', 'sort' => 5])->assertCreated()->json('data');
        $this->assertSame('Terrace', $area['name']);

        $table = $this->api('POST', 'restaurant/tables', ['location_id' => $this->locationA->id, 'area_id' => $area['id'], 'name' => 'P1', 'seats' => 6, 'x' => 10, 'y' => 20, 'shape' => 'round'])
            ->assertCreated()->assertJsonPath('data.status', 'free')->json('data');
        $this->assertSame([6, 10, 20, 'round'], [$table['seats'], $table['x'], $table['y'], $table['shape']]);

        $this->api('PUT', "restaurant/tables/{$table['id']}", ['seats' => 8, 'width' => 12])->assertOk()->assertJsonPath('data.seats', 8);
        $this->api('POST', "restaurant/tables/{$table['id']}/status", ['status' => 'reserved'])->assertOk()->assertJsonPath('data.status', 'reserved');
        $this->api('POST', "restaurant/tables/{$table['id']}/status", ['status' => 'occupied'])->assertUnprocessable();

        $list = $this->api('GET', "restaurant/tables?location_id={$this->locationA->id}")->assertOk()->json('data');
        $this->assertCount(4, $list);
        $this->api('GET', "restaurant/areas?location_id={$this->locationA->id}")->assertOk()->assertJsonCount(2, 'data');

        // An area with tables cannot be archived; an empty table and area can, and stay in the database.
        $this->api('POST', "restaurant/areas/{$area['id']}/archive")->assertConflict()->assertJsonPath('code', 'area_not_empty');
        $this->api('POST', "restaurant/tables/{$table['id']}/archive")->assertOk();
        $this->api('POST', "restaurant/areas/{$area['id']}/archive")->assertOk();
        $this->assertSame(3, Table::query()->whereNull('archived_at')->count());
        $this->assertNotNull(Area::query()->find($area['id'])->archived_at);
        $this->assertSame([1, 1, 1], [AuditEntry::query()->where('action', 'restaurant.table.archive')->count(), AuditEntry::query()->where('action', 'restaurant.area.archive')->count(), AuditEntry::query()->where('action', 'restaurant.table.update')->where('auditable_id', $table['id'])->count() >= 1 ? 1 : 0]);
    }

    public function test_a_name_is_unique_per_location_ignoring_case(): void
    {
        $this->api('POST', 'restaurant/tables', ['location_id' => $this->locationA->id, 'area_id' => $this->hall->id, 'name' => 't1'])->assertUnprocessable()->assertJsonPath('code', 'name_taken');
        $this->api('POST', 'restaurant/areas', ['location_id' => $this->locationA->id, 'name' => 'MAIN HALL'])->assertUnprocessable()->assertJsonPath('code', 'name_taken');
        $this->api('POST', 'restaurant/stations', ['location_id' => $this->locationA->id, 'name' => 'bar', 'kind' => 'bar'])->assertUnprocessable()->assertJsonPath('code', 'name_taken');
    }

    public function test_the_area_of_a_table_must_belong_to_its_location(): void
    {
        $other = $this->inTenant(fn () => Area::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'location_id' => $this->locationB->id, 'name' => 'Hall B']));

        $this->api('POST', 'restaurant/tables', ['location_id' => $this->locationA->id, 'area_id' => $other->id, 'name' => 'X1'])
            ->assertUnprocessable()->assertJsonPath('code', 'area_unknown');
        $this->api('POST', 'restaurant/tables', ['location_id' => $this->locationA->id, 'area_id' => $this->hall->id, 'name' => 'X1', 'seats' => 0])->assertUnprocessable();
    }

    public function test_the_floor_is_managed_only_where_the_right_is_held(): void
    {
        $body = ['location_id' => $this->locationA->id, 'name' => 'Patio'];
        // A waiter and the kitchen read the floor but do not change it.
        $this->api('GET', "restaurant/tables?location_id={$this->locationA->id}", [], $this->waiter)->assertOk();
        $this->api('POST', 'restaurant/areas', $body, $this->waiter)->assertForbidden();
        $this->api('PUT', "restaurant/areas/{$this->hall->id}", ['name' => 'X'], $this->cook)->assertForbidden();
        // The manager of branch A manages A, not B.
        $this->api('POST', 'restaurant/areas', $body, $this->manager)->assertCreated();
        $this->api('POST', 'restaurant/areas', ['location_id' => $this->locationB->id, 'name' => 'Patio'], $this->manager)->assertForbidden();
        // Someone with no restaurant right at all.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->api('GET', 'restaurant/areas', [], $cashier)->assertForbidden();
    }

    public function test_another_tenants_floor_is_never_found(): void
    {
        $other = $this->otherRestaurantTenant();
        $this->json('PUT', "/api/v1/restaurant/areas/{$this->hall->id}", ['name' => 'Mine now'], $this->headersFor($other['user']))->assertNotFound();
        $this->json('POST', '/api/v1/restaurant/areas', ['location_id' => $this->locationA->id, 'name' => 'Sneak'], $this->headersFor($other['user']))->assertStatus(422);
        $this->assertSame('Main hall', $this->inTenant(fn () => Area::query()->find($this->hall->id)->name));
    }

    public function test_the_module_must_be_active(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('restaurant'));

        $this->api('GET', 'restaurant/areas')->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }

    public function test_stations_route_categories_and_keep_one_default_per_location(): void
    {
        $dessert = $this->inTenant(fn () => ItemCategory::create(['name' => 'Desserts']));

        $cold = $this->api('POST', 'restaurant/stations', [
            'location_id' => $this->locationA->id, 'name' => 'Cold section', 'kind' => 'kitchen', 'printer' => 'EPSON-2', 'is_default' => true, 'category_ids' => [$dessert->id, $this->food->id],
        ])->assertCreated()->json('data');

        $this->assertTrue($cold['is_default']);
        $this->assertEqualsCanonicalizing([$dessert->id, $this->food->id], $cold['category_ids']);
        // The kitchen stepped down as default and lost the food category to the cold section.
        $this->assertFalse(Station::query()->find($this->kitchen->id)->is_default);
        $this->assertSame([], StationRoute::query()->where('station_id', $this->kitchen->id)->pluck('item_category_id')->all());
        $this->assertSame(1, Station::query()->where('is_default', true)->count());

        $this->api('PUT', "restaurant/stations/{$cold['id']}", ['category_ids' => []])->assertOk()->assertJsonPath('data.category_ids', []);
        $this->api('PUT', "restaurant/stations/{$cold['id']}", ['name' => 'Cold'])->assertOk()->assertJsonPath('data.name', 'Cold');
        $this->api('GET', "restaurant/stations?location_id={$this->locationA->id}")->assertOk()->assertJsonCount(3, 'data');
        $this->api('POST', "restaurant/stations/{$cold['id']}/archive")->assertOk();
        $this->assertSame(2, Station::query()->whereNull('archived_at')->count());
        $this->api('POST', 'restaurant/stations', ['location_id' => $this->locationA->id, 'name' => 'Bad', 'kind' => 'spaceship'])->assertUnprocessable();
    }

    public function test_stations_are_managed_by_the_right_holder_at_the_location(): void
    {
        $body = ['location_id' => $this->locationA->id, 'name' => 'Pizza', 'kind' => 'kitchen'];
        $this->api('POST', 'restaurant/stations', $body, $this->waiter)->assertForbidden();
        $this->api('GET', "restaurant/stations?location_id={$this->locationA->id}", [], $this->cook)->assertOk();
        $this->api('POST', 'restaurant/stations', $body, $this->cook)->assertForbidden();
        $this->api('POST', 'restaurant/stations', $body, $this->manager)->assertCreated();
        $other = $this->otherRestaurantTenant();
        $this->json('PUT', "/api/v1/restaurant/stations/{$this->bar->id}", ['name' => 'x'], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_modifier_groups_keep_old_modifiers_when_they_leave_the_group(): void
    {
        $group = $this->api('POST', 'restaurant/modifier-groups', [
            'company_id' => $this->acme->id, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'currency' => 'KES',
            'item_ids' => [$this->burger->id], 'category_ids' => [$this->drinks->id],
            'modifiers' => [['name' => 'Extra shot', 'price_minor' => '5000'], ['name' => 'No onions', 'price_minor' => '0']],
        ])->assertCreated()->json('data');

        $this->assertSame([$this->burger->id], $group['item_ids']);
        $this->assertSame([$this->drinks->id], $group['category_ids']);
        $this->assertSame(['5000', '0'], array_column($group['modifiers'], 'price_minor'));

        $shot = $group['modifiers'][0];
        $updated = $this->api('PUT', "restaurant/modifier-groups/{$group['id']}", ['modifiers' => [['id' => $shot['id'], 'name' => 'Double shot', 'price_minor' => '8000']]])->assertOk()->json('data');

        $this->assertSame('Double shot', collect($updated['modifiers'])->firstWhere('id', $shot['id'])['name']);
        $this->assertNotNull(collect($updated['modifiers'])->firstWhere('name', 'No onions')['archived_at'], 'left out, archived, not deleted');
        $this->assertSame(2, Modifier::query()->count());
        $this->assertSame([$this->burger->id], $updated['item_ids'], 'links untouched when not sent');

        $this->api('PUT', "restaurant/modifier-groups/{$group['id']}", ['modifiers' => [['id' => $this->id(), 'name' => 'x', 'price_minor' => '1']]])->assertUnprocessable()->assertJsonPath('code', 'modifier_unknown');
        $this->api('POST', 'restaurant/modifier-groups', ['company_id' => $this->acme->id, 'name' => 'Bad', 'min_select' => 3, 'max_select' => 1, 'currency' => 'KES'])->assertUnprocessable();
        $this->api('POST', 'restaurant/modifier-groups', ['company_id' => $this->acme->id, 'name' => 'Bad', 'min_select' => 0, 'max_select' => 1, 'currency' => 'KES', 'modifiers' => [['name' => 'x', 'price_minor' => '-5']]])->assertUnprocessable();
        $this->api('POST', "restaurant/modifier-groups/{$group['id']}/archive")->assertOk();
        $this->api('GET', 'restaurant/modifier-groups')->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'restaurant/modifier-groups?archived=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_modifier_groups_belong_to_a_company_and_are_managed_by_company_level_rights(): void
    {
        $body = ['company_id' => $this->acme->id, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 1, 'currency' => 'KES'];
        $this->api('POST', 'restaurant/modifier-groups', $body, $this->waiter)->assertForbidden();
        // The branch manager holds the right at a branch, not for the whole company.
        $this->api('POST', 'restaurant/modifier-groups', $body, $this->manager)->assertForbidden();
        $admin = $this->userWith('admin', Scope::company($this->acme->id));
        $this->api('POST', 'restaurant/modifier-groups', $body, $admin)->assertCreated();
        $this->api('GET', 'restaurant/modifier-groups', [], $this->waiter)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_service_charge_settings_are_the_companys_and_the_items_must_not_be_stock(): void
    {
        $url = "restaurant/companies/{$this->acme->id}/settings";
        $this->api('GET', $url)->assertOk()->assertJsonPath('data.service_charge_percent', '0.0000')->assertJsonPath('data.tip_presets', []);

        $this->api('PUT', $url, ['service_charge_percent' => '12.5', 'service_charge_item_id' => $this->serviceItem->id, 'tip_item_id' => $this->tipItem->id, 'tip_presets' => [5, 10, 10, 15]])
            ->assertOk()->assertJsonPath('data.service_charge_percent', '12.5000')->assertJsonPath('data.tip_presets', [5, 10, 15])
            ->assertJsonPath('data.service_charge_item_id', $this->serviceItem->id);

        $this->api('PUT', $url, ['service_charge_percent' => '12.5', 'tip_item_id' => $this->soap->id])->assertUnprocessable()->assertJsonPath('code', 'item_must_not_be_stock');
        $this->api('PUT', $url, ['service_charge_percent' => '31'])->assertUnprocessable()->assertJsonValidationErrors('service_charge_percent');
        $this->api('PUT', $url, ['service_charge_percent' => '-1'])->assertUnprocessable();
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.settings.create')->count());

        // Reading is open to those who work the floor; changing it only to the right holder at the company.
        $this->api('GET', $url, [], $this->waiter)->assertOk()->assertJsonPath('data.service_charge_percent', '12.5000');
        $this->api('PUT', $url, ['service_charge_percent' => '5'], $this->waiter)->assertForbidden();
        $this->api('PUT', $url, ['service_charge_percent' => '5'], $this->manager)->assertForbidden();
        $this->api('GET', $url, [], $this->userWith('cashier', Scope::location($this->locationA->id)))->assertNotFound();
        $other = $this->otherRestaurantTenant();
        $this->json('GET', "/api/v1/{$url}", [], $this->headersFor($other['user']))->assertNotFound();
    }
}
