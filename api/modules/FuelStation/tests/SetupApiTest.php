<?php

namespace Modules\FuelStation\Tests;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Tests\Concerns\BuildsFuel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FUEL-02 to FUEL-08, FUEL-20, FUEL-21, RBAC-01: set-up endpoints, prices, fleet and vouchers, permission-denied and other-tenant cases.
class SetupApiTest extends TestCase
{
    use BuildsFuel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFuel();
    }

    public function test_settings_default_to_zero_and_change_with_an_audit_entry(): void
    {
        $url = "/api/v1/companies/{$this->acme->id}/fuel-settings";
        $this->getJson($url, $this->headersFor())->assertOk()->assertJsonPath('data.cash_variance_tolerance_minor', '0')->assertJsonPath('data.litre_tolerance', '0.000');
        $this->putJson($url, ['cash_variance_tolerance_minor' => 5000, 'litre_tolerance' => '2.5', 'loss_tolerance_percent' => '0.3', 'delivery_tolerance_percent' => 0.5], $this->headersFor())
            ->assertOk()->assertJsonPath('data.litre_tolerance', '2.500')->assertJsonPath('data.loss_tolerance_percent', '0.3000');
        $this->putJson($url, ['loss_tolerance_percent' => 101], $this->headersFor())->assertStatus(422);
        $this->assertGreaterThan(0, $this->audited('fuel.settings.create'));
        // A station manager reads but a branch role cannot change company settings.
        $this->getJson($url, $this->headersFor($this->manager))->assertOk();
        $this->putJson($url, ['litre_tolerance' => '9'], $this->headersFor($this->manager))->assertForbidden();
    }

    public function test_stations_products_tanks_pumps_and_nozzles_are_created_changed_and_archived(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('fuel'));
        $diesel = $this->inTenant(fn () => Item::create(['code' => 'AGO', 'name' => 'Diesel', 'type' => 'stock', 'base_uom_id' => $this->litre->id, 'tax_category_id' => $this->goods->id]));

        // A second location becomes a station; one that already is, is refused.
        $this->postJson('/api/v1/fuel/stations', ['location_id' => $this->locationA->id, 'currency' => 'KES'], $this->headersFor())->assertStatus(422);
        $b = $this->postJson('/api/v1/fuel/stations', ['location_id' => $this->locationB->id, 'currency' => 'KES'], $this->headersFor())->assertCreated()->json('data');

        // A product needs a stock item in litres.
        $this->postJson('/api/v1/fuel/products', ['company_id' => $this->acme->id, 'item_id' => $this->soap->id, 'fuel_type' => 'petrol'], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'item_not_litre');
        $p = $this->postJson('/api/v1/fuel/products', ['company_id' => $this->acme->id, 'item_id' => $diesel->id, 'fuel_type' => 'diesel', 'grade' => 'Regular'], $this->headersFor())->assertCreated()->json('data');
        $this->postJson('/api/v1/fuel/products', ['company_id' => $this->acme->id, 'item_id' => $diesel->id, 'fuel_type' => 'diesel'], $this->headersFor())->assertStatus(422);
        $this->patchJson("/api/v1/fuel/products/{$p['id']}", ['grade' => 'Premium'], $this->headersFor())->assertOk()->assertJsonPath('data.grade', 'Premium');

        $tank = $this->postJson('/api/v1/fuel/tanks', ['location_id' => $this->locationB->id, 'product_id' => $p['id'], 'code' => 'D1', 'name' => 'Diesel 1', 'capacity_litres' => '20000'], $this->headersFor())->assertCreated()->assertJsonPath('data.book_litres', null)->json('data');
        $this->postJson('/api/v1/fuel/tanks', ['location_id' => $this->locationB->id, 'product_id' => $p['id'], 'code' => 'd1', 'name' => 'Dup', 'capacity_litres' => '1'], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'code_used');
        $pump = $this->postJson('/api/v1/fuel/pumps', ['location_id' => $this->locationB->id, 'code' => '1', 'name' => 'Pump 1'], $this->headersFor())->assertCreated()->json('data');
        $nozzle = $this->postJson('/api/v1/fuel/nozzles', ['pump_id' => $pump['id'], 'tank_id' => $tank['id'], 'code' => 'A'], $this->headersFor())->assertCreated()->json('data');
        // A nozzle cannot draw from another station's tank.
        $this->postJson('/api/v1/fuel/nozzles', ['pump_id' => $pump['id'], 'tank_id' => $this->tank->id, 'code' => 'B'], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'nozzle_tank_station');
        $this->getJson('/api/v1/fuel/pumps?location_id='.$this->locationB->id, $this->headersFor())->assertOk()->assertJsonPath('data.0.nozzles.0.code', 'A');

        // In-use archiving is refused, then works from the nozzle up.
        $this->postJson("/api/v1/fuel/tanks/{$tank['id']}/archive", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'tank_in_use');
        $this->postJson("/api/v1/fuel/nozzles/{$nozzle['id']}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/fuel/pumps/{$pump['id']}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/fuel/tanks/{$tank['id']}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/fuel/products/{$p['id']}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/fuel/stations/{$b['id']}/archive", [], $this->headersFor())->assertOk();
        foreach (['fuel.station.create', 'fuel.product.create', 'fuel.tank.create', 'fuel.pump.create', 'fuel.nozzle.create', 'fuel.nozzle.archive', 'fuel.tank.archive'] as $action) {
            $this->assertGreaterThan(0, $this->audited($action), $action);
        }
    }

    public function test_prices_are_effective_dated_immutable_audited_and_never_in_the_past(): void
    {
        $now = now();
        $this->postJson('/api/v1/fuel/prices', ['location_id' => $this->locationA->id, 'product_id' => $this->product->id, 'price_minor' => 19000, 'effective_from' => $now->copy()->subHour()->toIso8601String()], $this->headersFor($this->manager))
            ->assertStatus(422)->assertJsonPath('code', 'price_in_past');
        $new = $this->postJson('/api/v1/fuel/prices', ['location_id' => $this->locationA->id, 'product_id' => $this->product->id, 'price_minor' => 19000, 'effective_from' => $now->copy()->addDay()->toIso8601String(), 'reference' => 'Notice 15 Oct'], $this->headersFor($this->manager))
            ->assertCreated()->assertJsonPath('data.currency', 'KES')->assertJsonPath('data.entered_by.id', $this->manager->id)->json('data');
        $this->postJson('/api/v1/fuel/prices', ['location_id' => $this->locationA->id, 'product_id' => $this->product->id, 'price_minor' => 19100, 'effective_from' => $now->copy()->addDay()->toIso8601String()], $this->headersFor($this->manager))->assertStatus(422);

        // The price in force today is still the old one; the new one is scheduled and can be withdrawn.
        $this->getJson('/api/v1/fuel/prices?current=1', $this->headersFor($this->supervisor))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price_minor', '18050');
        $this->getJson('/api/v1/fuel/prices', $this->headersFor($this->supervisor))->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/fuel/prices/{$new['id']}/archive", [], $this->headersFor($this->manager))->assertOk();
        $current = $this->inTenant(fn () => Price::query()->where('price_minor', 18050)->firstOrFail());
        $this->postJson("/api/v1/fuel/prices/{$current->id}/archive", [], $this->headersFor($this->manager))->assertStatus(409)->assertJsonPath('code', 'price_started');
        $this->assertSame(2, $this->audited('fuel.price.create') + $this->audited('fuel.price.archive') - 1);

        // A supervisor reads prices but cannot set them; an attendant cannot even read the list.
        $this->postJson('/api/v1/fuel/prices', ['location_id' => $this->locationA->id, 'product_id' => $this->product->id, 'price_minor' => 1], $this->headersFor($this->supervisor))->assertForbidden();
        $this->getJson('/api/v1/fuel/prices', $this->headersFor($this->attendant))->assertForbidden();
    }

    public function test_permissions_are_checked_at_the_station_and_other_tenants_see_nothing(): void
    {
        // Prices of a product set at location B by a user who only holds roles at A are refused (RBAC-04).
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('fuel'));
        $this->postJson('/api/v1/fuel/stations', ['location_id' => $this->locationB->id, 'currency' => 'KES'], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/fuel/pumps', ['location_id' => $this->locationB->id, 'code' => 'X', 'name' => 'X'], $this->headersFor($this->manager))->assertForbidden();
        $this->postJson('/api/v1/fuel/pumps', ['location_id' => $this->locationA->id, 'code' => 'X', 'name' => 'X'], $this->headersFor($this->supervisor))->assertForbidden();
        $this->getJson('/api/v1/fuel/shifts', $this->headersFor($this->attendant))->assertOk();
        $this->getJson('/api/v1/fuel/settlements', $this->headersFor($this->attendant))->assertForbidden();
        $this->getJson('/api/v1/fuel/reports/shift-summary', $this->headersFor($this->attendant))->assertForbidden();

        // The other tenant's owner finds none of this tenant's records, and cannot use this tenant's ids (FUEL-28).
        $other = $this->otherTenant();
        $theirs = $this->headersFor($other['user']);
        $this->getJson("/api/v1/fuel/tanks/{$this->tank->id}", $theirs)->assertNotFound();
        $this->getJson('/api/v1/fuel/stations', $theirs)->assertForbidden();
        $this->postJson('/api/v1/fuel/prices', ['location_id' => $this->locationA->id, 'product_id' => $this->product->id, 'price_minor' => 1], $theirs)->assertStatus(403);
    }

    public function test_vehicles_and_vouchers(): void
    {
        $customer = $this->customer($this->acme->id);
        $vehicle = $this->postJson('/api/v1/fuel/vehicles', ['company_id' => $this->acme->id, 'party_id' => $customer->id, 'registration' => 'kdd 123a', 'fuel_type' => 'diesel', 'daily_litre_limit' => '200'], $this->headersFor($this->manager))
            ->assertCreated()->assertJsonPath('data.registration', 'KDD123A')->json('data');
        $this->postJson('/api/v1/fuel/vehicles', ['company_id' => $this->acme->id, 'party_id' => $customer->id, 'registration' => 'KDD-123A'], $this->headersFor($this->manager))->assertStatus(422)->assertJsonPath('code', 'registration_used');
        $this->patchJson("/api/v1/fuel/vehicles/{$vehicle['id']}", ['status' => 'blocked'], $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->postJson('/api/v1/fuel/vehicles', ['company_id' => $this->acme->id, 'party_id' => $customer->id, 'registration' => 'KZZ 1'], $this->headersFor($this->attendant))->assertForbidden();

        $voucher = $this->postJson('/api/v1/fuel/vouchers', ['company_id' => $this->acme->id, 'value_minor' => 500000, 'currency' => 'KES', 'code' => 'abc-123'], $this->headersFor($this->manager))->assertCreated()->assertJsonPath('data.code', 'ABC123')->assertJsonPath('data.balance_minor', '500000')->json('data');
        $auto = $this->postJson('/api/v1/fuel/vouchers', ['company_id' => $this->acme->id, 'value_minor' => 100, 'currency' => 'KES'], $this->headersFor($this->manager))->assertCreated()->json('data');
        $this->assertSame(10, strlen($auto['code']));
        $this->postJson('/api/v1/fuel/vouchers', ['company_id' => $this->acme->id, 'value_minor' => 100, 'currency' => 'KES', 'code' => 'ABC123'], $this->headersFor($this->manager))->assertStatus(422)->assertJsonPath('code', 'voucher_code_used');
        $this->postJson("/api/v1/fuel/vouchers/{$voucher['id']}/void", ['reason' => 'Lost'], $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.status', 'void');
        $this->postJson("/api/v1/fuel/vouchers/{$voucher['id']}/void", ['reason' => 'Again'], $this->headersFor($this->manager))->assertStatus(409);
        $this->getJson('/api/v1/fuel/vouchers?status=active', $this->headersFor($this->supervisor))->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, $this->audited('fuel.voucher.void'));
    }
}
