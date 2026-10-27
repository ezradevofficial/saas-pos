<?php

namespace Modules\Restaurant\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Facades\Event;
use Modules\POS\Models\Sale;
use Modules\Restaurant\Events\OrderClosed;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\Setting;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-16, REST-13, POS-13: a table is closed into normal POS sales; the
// sale carries `extensions.restaurant`, and the module settles the order from
// the sale's events, once per sale, reading no POS table.
class SettlementTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    private string $shift;

    private array $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
        $this->order = $this->orderRecord([$this->sent($this->orderLine()), $this->orderLine(['name' => 'Salad', 'unit_price_minor' => '40000'])]);
        $this->store($this->order);
        $this->inTenant(function () {
            $exempt = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'EX_T', 'name' => 'Exempt test', 'kind' => 'exempt']);
            $category = TaxCategory::create(['name' => 'Exempt']);
            TaxCategoryCode::create(['tax_category_id' => $category->id, 'company_id' => $this->acme->id, 'tax_code_id' => $exempt->id]);
            $this->tipItem->forceFill(['tax_category_id' => $category->id])->save();
            Setting::create(['company_id' => $this->acme->id, 'service_charge_percent' => '10', 'service_charge_item_id' => $this->serviceItem->id, 'tip_item_id' => $this->tipItem->id]);
            $this->exemptId = $exempt->id;
        });
    }

    private string $exemptId;

    /** A sale of the order's whole bill: lines, service charge and tip, with the restaurant extension. */
    private function sale(int $seq, array $extension, array $overrides = []): array
    {
        $lines = [
            $this->line(['item_id' => $this->burger->id, 'qty' => '1', 'unit_price_minor' => '90000', 'list_price_minor' => '90000', 'total_minor' => '90000', 'tax_minor' => '10000', 'price_list_id' => null]),
            $this->line(['item_id' => $this->serviceItem->id, 'qty' => '1', 'unit_price_minor' => '9000', 'list_price_minor' => '9000', 'total_minor' => '9000', 'tax_minor' => '1000', 'price_list_id' => null]),
            $this->line(['item_id' => $this->tipItem->id, 'qty' => '1', 'unit_price_minor' => '2000', 'list_price_minor' => '2000', 'total_minor' => '2000', 'tax_minor' => '0', 'tax_inclusive' => false, 'tax_code_id' => $this->exemptId, 'tax_rate' => null, 'price_list_id' => null]),
        ];

        return $this->saleBody($this->shift, $seq, array_replace(['lines' => $lines, 'extensions' => ['restaurant' => array_replace(['order_id' => $this->order['id'], 'mode' => 'full', 'part' => 1, 'parts' => 1, 'final' => true, 'line_ids' => []], $extension)]], $overrides));
    }

    private function stored(array $sale): string
    {
        $response = $this->upload([$sale]);
        $this->assertSame('stored', $response->json('results.0.status'), (string) $response->getContent());

        return $sale['id'];
    }

    public function test_the_final_sale_settles_and_closes_the_order(): void
    {
        Event::fake([OrderClosed::class]);
        $sale = $this->sale(1, []);
        $this->stored($sale);

        $settlement = Settlement::query()->sole();
        $this->assertSame([$sale['id'], 'full', true, 'KES', '101000', '2000', '9000'], [$settlement->sale_id, $settlement->mode, $settlement->final, $settlement->currency, (string) $settlement->total_minor, (string) $settlement->tip_minor, (string) $settlement->service_charge_minor]);
        $order = Order::query()->find($this->order['id']);
        $this->assertSame('closed', $order->status);
        $this->assertNotNull($order->closed_at);
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.order.close')->count());
        Event::assertDispatched(OrderClosed::class, fn (OrderClosed $e) => $e->orderId === $this->order['id']);

        // A sale of it again (the same id) settles nothing twice.
        $this->stored($sale);
        $this->assertSame(1, Settlement::query()->count());
    }

    public function test_a_split_bill_is_settled_part_by_part_and_closes_with_the_final_part(): void
    {
        $this->stored($this->sale(1, ['mode' => 'equal', 'part' => 1, 'parts' => 2, 'final' => false]));

        $this->assertSame('open', Order::query()->find($this->order['id'])->status);
        $this->api('GET', "restaurant/orders/{$this->order['id']}")->assertOk()->assertJsonCount(1, 'data.settlements')->assertJsonPath('data.settlements.0.part', 1);

        $this->stored($this->sale(2, ['mode' => 'equal', 'part' => 2, 'parts' => 2, 'final' => true]));
        $this->assertSame('closed', Order::query()->find($this->order['id'])->status);
        $this->assertSame(2, Settlement::query()->count());
    }

    public function test_a_voided_sale_takes_its_settlement_back_and_reopens_the_table(): void
    {
        $sale = $this->sale(1, []);
        $this->stored($sale);
        $void = ['id' => $this->id(), 'sale_id' => $sale['id'], 'voided_by_id' => $this->owner->id, 'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong table', 'actor_proof' => $this->actorProof($this->owner->id, online: true)];

        $this->postJson('/api/v1/pos/voids', ['voids' => [$void]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->assertNotNull(Settlement::query()->sole()->voided_at);
        $order = Order::query()->find($this->order['id']);
        $this->assertSame('open', $order->status);
        $this->assertNull($order->closed_at);
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.order.reopen')->count());
    }

    public function test_a_voided_part_of_a_split_leaves_the_order_closed_while_another_part_stands(): void
    {
        $first = $this->sale(1, ['mode' => 'equal', 'part' => 1, 'parts' => 2, 'final' => false]);
        $this->stored($first);
        $this->stored($this->sale(2, ['mode' => 'equal', 'part' => 2, 'parts' => 2, 'final' => true]));
        $void = ['id' => $this->id(), 'sale_id' => $first['id'], 'voided_by_id' => $this->owner->id, 'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong card', 'actor_proof' => $this->actorProof($this->owner->id, online: true)];

        $this->postJson('/api/v1/pos/voids', ['voids' => [$void]], $this->tillHeaders())->assertOk();

        $this->assertSame('closed', Order::query()->find($this->order['id'])->status);
        $this->assertSame(1, Settlement::query()->whereNull('voided_at')->count());
    }

    public function test_sales_without_the_extension_or_for_an_unknown_order_settle_nothing(): void
    {
        $plain = $this->saleBody($this->shift, 1);
        $this->stored($plain);
        $this->stored($this->sale(2, ['order_id' => $this->id()]));

        $this->assertSame(0, Settlement::query()->count());
        $this->assertSame('open', Order::query()->find($this->order['id'])->status);
        $this->assertSame(2, Sale::query()->count(), 'the sales themselves are kept: the till is never refused');
    }

    public function test_a_sale_of_another_locations_order_settles_nothing(): void
    {
        $elsewhere = $this->inTenant(fn () => Order::create([
            'company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'location_id' => $this->locationB->id, 'kind' => 'tab', 'tab_name' => 'B', 'waiter_id' => $this->waiter->id,
            'currency' => 'KES', 'opened_at' => now(), 'header_at' => now(),
        ]));
        $this->stored($this->sale(1, ['order_id' => $elsewhere->id]));

        $this->assertSame(0, Settlement::query()->count());
        $this->assertSame('open', Order::query()->find($elsewhere->id)->status);
    }

    public function test_nothing_is_settled_while_the_module_is_off(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('restaurant'));
        $this->stored($this->sale(1, []));

        $this->assertSame(0, Settlement::query()->count());
    }

    public function test_the_order_is_one_with_the_extension_in_the_sale_snapshot_only(): void
    {
        // The extension travels with the sale as the till sent it; Restaurant keeps nothing of it on the sale.
        $sale = $this->sale(1, []);
        $this->stored($sale);

        $this->assertSame($this->order['id'], Sale::query()->find($sale['id'])->extensions['restaurant']['order_id']);
    }
}
