<?php

namespace Modules\Ecommerce\Tests;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Reservation;
use Modules\Ecommerce\Orders\Reservations;
use Modules\Ecommerce\Support\Tx;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-01, ECOM-02, ECOM-10, ECOM-14: store settings checked against the
// company, the catalogue and zones, permissions; stock reservations that
// never let two orders take the last unit.
class StoreSetupAndStockTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_a_store_is_set_up_with_the_companys_records_only(): void
    {
        $body = ['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'location_id' => $this->locationB->id, 'handle' => 'duka-b', 'name' => 'Duka B',
            'currency' => 'KES', 'price_list_id' => $this->list->id, 'payment_methods' => [['kind' => 'cod', 'payment_method_id' => $this->cash->id]]];

        $store = $this->api('POST', 'ecommerce/stores', $body)->assertCreated()->json('data');
        $this->assertSame(['draft', 'duka-b'], [$store['status'], $store['handle']]);
        $this->assertStringContainsString('/shop/duka-b', $store['share']['url']);

        // The handle is the tenant's once; the location must be of the branch; a cash method can't take M-Pesa.
        $this->api('POST', 'ecommerce/stores', $body)->assertUnprocessable()->assertJsonPath('code', 'handle_taken');
        $this->api('POST', 'ecommerce/stores', [...$body, 'handle' => 'x1', 'location_id' => $this->locationA->id])->assertUnprocessable()->assertJsonPath('code', 'location_not_of_branch');
        $this->api('POST', 'ecommerce/stores', [...$body, 'handle' => 'x2', 'payment_methods' => [['kind' => 'mpesa', 'payment_method_id' => $this->cash->id]]])
            ->assertUnprocessable()->assertJsonPath('code', 'payment_method_invalid');

        // Going live needs a pickup point when pickup is offered.
        $this->api('PATCH', "ecommerce/stores/{$store['id']}", ['status' => 'live', 'delivery_enabled' => false, 'pickup_enabled' => true])->assertUnprocessable()->assertJsonPath('code', 'store_not_ready');
        $this->api('PATCH', "ecommerce/stores/{$store['id']}", ['status' => 'live', 'pickup_location_ids' => [$this->locationB->id], 'pickup_enabled' => true])->assertOk()->assertJsonPath('data.status', 'live');

        // Another tenant's ids do not exist here.
        $other = $this->otherTenant();
        $this->api('POST', 'ecommerce/stores', [...$body, 'handle' => 'x3', 'company_id' => $other['company']->id])->assertUnprocessable();

        // A branch manager of A cannot set up a store at B.
        $this->api('POST', 'ecommerce/stores', [...$body, 'handle' => 'x4'], $this->manager)->assertForbidden();
    }

    public function test_products_and_zones_are_configuration(): void
    {
        $item = $this->inTenant(fn () => Item::create(['code' => 'MUG', 'name' => 'Coffee mug', 'type' => 'stock', 'base_uom_id' => $this->each->id]));
        $added = $this->api('POST', "ecommerce/stores/{$this->shop->id}/products", ['item_ids' => [$item->id]], $this->manager)->assertCreated()->json('data.0');
        $this->assertSame(['coffee-mug', false, true], [$added['slug'], $added['published'], $added['track_stock']]);

        $this->api('PATCH', "ecommerce/products/{$added['id']}", ['published' => true, 'slug' => 'widget'], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'slug_taken');
        $this->api('PATCH', "ecommerce/products/{$added['id']}", ['published' => true, 'description' => 'Holds 300 ml.'], $this->manager)->assertOk()->assertJsonPath('data.published', true);
        $this->api('POST', "ecommerce/products/{$added['id']}/archive", [], $this->manager)->assertOk()->assertJsonPath('data.published', false);

        $zone = $this->api('POST', "ecommerce/stores/{$this->shop->id}/zones", ['name' => 'Karen', 'fee_minor' => '35000', 'eta' => 'Same day'], $this->manager)->assertCreated()->json('data');
        $this->assertSame('35000', $zone['fee_minor']);
        $this->api('PATCH', "ecommerce/zones/{$zone['id']}", ['fee_minor' => '-1'], $this->manager)->assertUnprocessable();

        // A cashier reads nothing of the setup; a manager of B neither.
        $this->api('GET', "ecommerce/stores/{$this->shop->id}/products", [], $this->userWith('cashier', Scope::branch($this->branchA->id)))->assertNotFound();
        $this->api('POST', "ecommerce/stores/{$this->shop->id}/zones", ['name' => 'X', 'fee_minor' => '1'], $this->userWith('branch_manager', Scope::branch($this->branchB->id)))->assertNotFound();
    }

    public function test_two_orders_never_take_the_last_unit(): void
    {
        $this->stockUp('1');

        $first = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data');
        $this->assertSame('out_of_stock', $this->shopCall('GET', 'duka/products/widget')->json('data.availability.state'));

        // The second order for the same unit, priced while it was free, is refused at placement.
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertUnprocessable()->assertJsonPath('code', 'out_of_stock');
        $this->assertSame(1, $this->inTenant(fn () => Order::query()->count()));

        // The safety buffer keeps units back too.
        $this->api('POST', 'ecommerce/orders/'.$this->orderId($first['number']).'/cancel', ['reason' => 'Test'], $this->manager)->assertOk();
        $this->inTenant(fn () => $this->shop->forceFill(['safety_buffer' => '1'])->save());
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertUnprocessable()->assertJsonPath('code', 'out_of_stock');
    }

    public function test_placements_take_the_location_and_item_lock_in_order(): void
    {
        // ECOM-14: while one placement holds the lock of a location and item, another session cannot take it.
        $this->inTenant(function () {
            Tx::run(function () {
                app(Reservations::class)->lock($this->locationA->id, [$this->widget->id, $this->service->id]);
                $key = "ecom:stock:{$this->locationA->id}:{$this->widget->id}";
                $other = DB::connection('pgsql_owner');
                $this->assertFalse((bool) $other->selectOne('select pg_try_advisory_lock(hashtextextended(?, 0)) as got', [$key])->got, 'another session waits for it');
                $other->select('select pg_advisory_unlock_all()');
            });
        });

        // (Transaction-scoped: released when the placing transaction ends; the test's own transaction keeps it here.)
        $this->assertSame(0, $this->inTenant(fn () => Reservation::query()->count()));
    }
}
