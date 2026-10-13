<?php

namespace Modules\Inventory\Tests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Inventory\Listeners\MoveStockForSale;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// INV-14, INV-17, RBAC-08: POS sales move stock out of the till's location
// and voids and refunds bring it back at its cost, once; with Inventory
// off nothing happens; the till pulls the stock of its location.
class PosIntegrationTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private User $manager;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPos();
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    private function receive(string $qty, int $cost): void
    {
        $this->inTenant(fn () => app(StockLedger::class)->post(new Posting($this->acme->id, 'test.source', (string) Str::uuid7(), CarbonImmutable::now(), null, [
            new PostingLine($this->soap->id, $this->locationA->id, Movement::RECEIPT, Qty::of($qty), cost: BigInteger::of($cost), setsLastCost: true),
        ])));
    }

    private function onHand(): string
    {
        return $this->inTenant(fn () => Qty::show((string) (Balance::query()->where('item_id', $this->soap->id)->where('location_id', $this->locationA->id)->value('qty') ?? '0')));
    }

    private function sell(int $seq = 1, string $qty = '3'): array
    {
        $sale = $this->saleBody($this->shift, $seq, ['lines' => [$this->line(['qty' => $qty, 'tax_minor' => (string) (6250 * (int) $qty), 'total_minor' => (string) (56250 * (int) $qty)])]]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        return $sale;
    }

    /** A refund of $qty of the sale's line, applied (approved when the till's was held). */
    private function refund(array $sale, string $qty, int $seq): string
    {
        $id = $this->id();
        $total = (string) (56250 * (int) $qty);
        $stored = $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $id, 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(), 'reason' => 'Damaged', 'total_minor' => $total,
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => $qty]],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total]],
            'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders())->assertOk();

        if ($stored->json('results.0.refund_status') === 'held') {
            $this->postJson("/api/v1/pos/refunds/{$id}/approve", ['note' => 'Checked'], $this->headersFor())->assertOk();
        }

        return $id;
    }

    private function voidSale(array $sale): string
    {
        $id = $this->id();
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $id, 'sale_id' => $sale['id'], 'voided_by_id' => $this->owner->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Mistake', 'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders())->assertOk();

        if ($this->inTenant(fn () => SaleVoid::query()->findOrFail($id)->status) === 'held') {
            $this->postJson("/api/v1/pos/voids/{$id}/approve", ['note' => 'Checked'], $this->headersFor())->assertOk();
        }

        return $id;
    }

    /** The sale's own event, handled now (as a queued job delivered late). */
    private function handleSale(array $sale): void
    {
        app(MoveStockForSale::class)->handle(new SaleCompleted($this->owner->tenant_id, $sale['id'], $this->acme->id, $this->locationA->id));
    }

    public function test_a_void_handled_before_its_sale_leaves_stock_as_it_was(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->receive('10', 1000);
        Event::fake([SaleCompleted::class]);

        $sale = $this->sell(1, '3');
        $this->voidSale($sale);
        $this->assertSame('10', $this->onHand());

        $this->handleSale($sale);
        $this->handleSale($sale);

        $this->assertSame('10', $this->onHand());
        $this->assertSame(1, $this->inTenant(fn () => Movement::query()->count()));
    }

    public function test_a_partial_refund_handled_before_its_sale_is_counted_once(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->receive('10', 1000);
        Event::fake([SaleCompleted::class]);

        $sale = $this->sell(1, '3');
        $refund = $this->refund($sale, '1', 1);
        $this->assertSame('10', $this->onHand());

        $this->handleSale($sale);
        $this->assertSame('8', $this->onHand());

        // Duplicate events: the sale again, and the refund's own event late.
        $this->handleSale($sale);
        app(MoveStockForSale::class)->handle(new SaleRefunded($this->owner->tenant_id, $sale['id'], $refund));
        $this->assertSame('8', $this->onHand());
        $this->assertSame(['8.000000', '800'], $this->inTenant(fn () => [(string) CostState::query()->sole()->qty, (string) CostState::query()->sole()->value_minor]));
    }

    public function test_the_last_refunded_units_take_what_is_left_of_the_sales_value(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->receive('3', 1000);
        $sale = $this->sell(1, '3');

        foreach ([1, 2, 3] as $seq) {
            $this->refund($sale, '1', $seq);
        }

        $values = $this->inTenant(fn () => Movement::query()->where('kind', 'sale_return')->orderBy('created_at')->orderBy('id')->pluck('value_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([333, 333, 334], $values);
        $this->assertSame('3', $this->onHand());
    }

    public function test_with_inventory_off_a_sale_moves_nothing(): void
    {
        $this->sell();

        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->count()));
    }

    public function test_a_sale_moves_stock_out_once_and_a_refund_and_a_void_bring_it_back_at_its_cost(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->receive('10', 1000);

        $sale = $this->sell(1, '3');
        $this->assertSame('7', $this->onHand());
        [$out] = $this->inTenant(fn () => Movement::query()->where('source_type', 'pos.sale')->get()->all());
        $this->assertSame(['sale', '-3.000000', '-300'], [$out->kind, (string) $out->qty, (string) $out->value_minor]);

        // A redelivered event moves nothing more.
        $this->inTenant(fn () => SaleCompleted::dispatch($this->owner->tenant_id, $sale['id'], $this->acme->id, $this->locationA->id));
        $this->assertSame('7', $this->onHand());

        // The cost rises; a refund of one soap still comes back at 100.
        $this->receive('10', 5000);
        $refundId = $this->id();
        $stored = $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $refundId, 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => 1, 'receipt_number' => 'RF-L01-000001', 'refunded_at' => now()->toIso8601String(), 'reason' => 'Damaged', 'total_minor' => '56250',
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '1']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => '56250', 'amount_in_sale_minor' => '56250']],
            'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');

        // A refund held for review moves stock only once approved (POS H2).
        if ($stored->json('results.0.refund_status') === 'held') {
            $this->assertSame('17', $this->onHand());
            $this->postJson("/api/v1/pos/refunds/{$refundId}/approve", ['note' => 'Checked'], $this->headersFor())->assertOk();
        }

        $this->assertSame('18', $this->onHand());
        $return = $this->inTenant(fn () => Movement::query()->where('kind', 'sale_return')->sole());
        $this->assertSame(['100', $out->id], [(string) $return->value_minor, $return->reverses_movement_id]);

        // A second sale, voided: all of it comes back.
        $second = $this->sell(2, '2');
        $this->assertSame('16', $this->onHand());
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'sale_id' => $second['id'], 'voided_by_id' => $this->owner->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Mistake', 'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders())->assertOk();
        $void = $this->inTenant(fn () => SaleVoid::query()->where('sale_id', $second['id'])->sole());

        if ($void->status === 'held') {
            $this->postJson("/api/v1/pos/voids/{$void->id}/approve", ['note' => 'Checked'], $this->headersFor())->assertOk();
        }

        $this->assertSame('18', $this->onHand());
    }

    public function test_sales_never_fail_for_lack_of_stock_and_are_flagged(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));

        $this->sell(1, '4');

        $this->assertSame('-4', $this->onHand());
        $this->assertContains('negative_stock', $this->inTenant(fn () => Movement::query()->sole()->flagCodes()));
    }

    public function test_the_till_pulls_the_stock_of_its_location(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->receive('10', 1000);
        $this->sell(1, '2');

        $pull = $this->getJson('/api/v1/sync/pull?entities[]=inventory_stock', $this->tillHeaders())->assertOk();
        $rows = $pull->json('entities.inventory_stock.upserts');
        $this->assertSame([[$this->soap->id, '8']], array_map(fn ($r) => [$r['item_id'], $r['qty']], $rows));

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));
        $this->assertNull($this->getJson('/api/v1/sync/pull?entities[]=inventory_stock', $this->tillHeaders())->json('entities.inventory_stock'));
    }
}
