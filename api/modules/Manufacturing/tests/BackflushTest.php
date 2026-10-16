<?php

namespace Modules\Manufacturing\Tests;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Inventory\Events\StockMoved;
use Modules\Inventory\Listeners\MoveStockForManufacturing;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Manufacturing\Backflush\BackflushSales;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\BackflushLine;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MFG-07 to MFG-09, MFG-15, MFG-20: a POS sale of a made-to-order menu
// item consumes its recipe's ingredients from the till's location once
// (multi-level, in base units), a void or refund gives them back at their
// cost (refunds pro rata, the last one exactly what is left), the sold
// item itself never moves, and nothing happens unless Manufacturing,
// Inventory and POS are all on.
class BackflushTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private User $manager;

    private string $shift;

    private Item $burger;

    private Item $patty;

    private Item $bun;

    private Item $mayo;

    private Item $chilli;

    private Item $sauce;

    private string $gramId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->setUpPos();
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('inventory');
            app(ModuleRegistry::class)->activate('manufacturing');
            $litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'kind' => 'volume']);
            $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram', 'kind' => 'weight']);
            $gram = Uom::create(['code' => 'G', 'name' => 'Gram', 'kind' => 'weight']);
            $this->gramId = $gram->id;
            $stock = fn (string $code, Uom $uom) => Item::create(['code' => $code, 'name' => ucfirst(strtolower($code)), 'type' => 'stock', 'base_uom_id' => $uom->id]);
            $this->patty = $stock('PATTY', $this->each);
            $this->bun = $stock('BUN', $this->each);
            $this->mayo = $stock('MAYO', $litre);
            $this->chilli = $stock('CHILLI', $kg);
            ItemUom::create(['item_id' => $this->chilli->id, 'uom_id' => $gram->id, 'factor' => '0.001']);
            $this->sauce = Item::create(['code' => 'SAUCE', 'name' => 'Sauce', 'type' => 'non_stock', 'base_uom_id' => $litre->id]);
            $this->burger = Item::create(['code' => 'BURGER', 'name' => 'Burger', 'type' => 'kit', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);

            // Stock at the till's location: patties at 150.00, buns at 20.00, mayonnaise 400.00 a litre, chilli 900.00 a kg.
            foreach ([[$this->patty, '50', 750000], [$this->bun, '50', 100000], [$this->mayo, '10', 400000], [$this->chilli, '2', 180000], [$this->soap, '10', 100000]] as [$item, $qty, $cost]) {
                app(StockLedger::class)->post(new Posting($this->acme->id, 'test.source', (string) Str::uuid7(), CarbonImmutable::now(), null, [
                    new PostingLine($item->id, $this->locationA->id, Movement::RECEIPT, Qty::of($qty), cost: BigInteger::of($cost), setsLastCost: true),
                ]));
            }
        });

        // The house sauce (1 L: 0.8 L mayonnaise, 50 g chilli) and the burger (a patty, a bun, 30 ml of sauce exploded).
        $this->recipe($this->sauce, [['item_id' => $this->mayo->id, 'qty' => '0.8'], ['item_id' => $this->chilli->id, 'qty' => '50', 'uom_id' => $this->gramId]]);
        $this->recipe($this->burger, [['item_id' => $this->patty->id, 'qty' => '1'], ['item_id' => $this->bun->id, 'qty' => '1'], ['item_id' => $this->sauce->id, 'qty' => '0.03', 'explode' => true]]);

        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    private function recipe(Item $item, array $lines): void
    {
        $bom = $this->postJson('/api/v1/manufacturing/boms', ['company_id' => $this->acme->id, 'item_id' => $item->id, 'kind' => 'recipe', 'version' => ['output_qty' => '1', 'lines' => $lines]], $this->headersFor())
            ->assertCreated()->json('data');
        $this->postJson("/api/v1/manufacturing/bom-versions/{$bom['draft_id']}/publish", ['effective_from' => now()->subDay()->toIso8601String()], $this->headersFor())->assertOk();
    }

    /** A sale of $burgers burgers (and $soaps soaps). */
    private function sell(int $seq, string $burgers, string $soaps = '0'): array
    {
        $lines = [$this->line(['item_id' => $this->burger->id, 'qty' => $burgers, 'tax_minor' => (string) (6250 * (int) $burgers), 'total_minor' => (string) (56250 * (int) $burgers)])];

        if ($soaps !== '0') {
            $lines[] = $this->line(['qty' => $soaps, 'tax_minor' => (string) (6250 * (int) $soaps), 'total_minor' => (string) (56250 * (int) $soaps)]);
        }

        $sale = $this->saleBody($this->shift, $seq, ['lines' => $lines]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        return $sale;
    }

    private function refund(array $sale, string $qty, int $seq): string
    {
        $id = $this->id();
        $total = (string) (56250 * (int) $qty);
        $stored = $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $id, 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(), 'reason' => 'Cold', 'total_minor' => $total,
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => $qty]],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total]],
            'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders())->assertOk();

        if ($stored->json('results.0.refund_status') === 'held') {
            $this->postJson("/api/v1/pos/refunds/{$id}/approve", ['note' => 'Checked'], $this->headersFor())->assertOk();
        }

        return $id;
    }

    private function voidSale(array $sale): void
    {
        $id = $this->id();
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $id, 'sale_id' => $sale['id'], 'voided_by_id' => $this->owner->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Mistake', 'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders())->assertOk();

        if ($this->inTenant(fn () => SaleVoid::query()->findOrFail($id)->status) === 'held') {
            $this->postJson("/api/v1/pos/voids/{$id}/approve", ['note' => 'Checked'], $this->headersFor())->assertOk();
        }
    }

    /** @return array<string, string> item code => on hand at the till's location */
    private function stock(): array
    {
        return $this->inTenant(fn () => collect([$this->patty, $this->bun, $this->mayo, $this->chilli, $this->soap, $this->burger])->mapWithKeys(fn (Item $item) => [
            $item->code => Qty::show((string) (Balance::query()->where('item_id', $item->id)->where('location_id', $this->locationA->id)->value('qty') ?? '0')),
        ])->all());
    }

    public function test_a_sale_consumes_the_recipe_once_and_never_moves_the_menu_item(): void
    {
        $sale = $this->sell(1, '3', '2');

        // 3 patties, 3 buns, 3 × 0.03 × 0.8 = 0.072 L mayonnaise, 3 × 0.03 × 0.05 = 0.0045 kg chilli; the soap is a stock sale.
        $this->assertSame(['PATTY' => '47', 'BUN' => '47', 'MAYO' => '9.928', 'CHILLI' => '1.9955', 'SOAP' => '8', 'BURGER' => '0'], $this->stock());
        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->where('item_id', $this->burger->id)->count()));
        $this->assertSame(['recipe_consumption'], array_values(array_unique($this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.backflush')->pluck('kind')->all()))));

        // Valued by Inventory: 3 × 15,000 + 3 × 2,000 + 0.072 × 40,000 + 0.0045 × 90,000 = 54,285.
        $backflush = $this->inTenant(fn () => Backflush::query()->where('source_id', $sale['id'])->sole());
        $this->assertNotNull($backflush->valued_at);
        $this->assertSame(54285, $this->inTenant(fn () => (int) BackflushLine::query()->where('backflush_id', $backflush->id)->sum('value_minor')));

        // MFG-08: the event handled again consumes nothing more.
        app(BackflushSales::class)->handle(new SaleCompleted($this->owner->tenant_id, $sale['id'], $this->acme->id, $this->locationA->id));
        $this->assertSame('47', $this->stock()['PATTY']);
        $this->assertSame(1, $this->inTenant(fn () => Backflush::query()->count()));
        $this->getJson('/api/v1/manufacturing/backflushes', $this->headersFor())->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value.amount_minor', '54285')->assertJsonPath('data.0.menu_items.0.code', 'BURGER');
    }

    public function test_refunds_give_back_their_share_and_the_last_one_exactly_what_is_left(): void
    {
        $sale = $this->sell(1, '3');
        $this->refund($sale, '1', 1);

        // A third back: 1 patty, 1 bun, 0.024 L, 0.0015 kg, at the cost they left at.
        $this->assertSame(['PATTY' => '48', 'BUN' => '48', 'MAYO' => '9.952', 'CHILLI' => '1.997'], array_slice($this->stock(), 0, 4));

        $this->refund($sale, '2', 2);
        $this->assertSame(['PATTY' => '50', 'BUN' => '50', 'MAYO' => '10', 'CHILLI' => '2'], array_slice($this->stock(), 0, 4));

        // Everything consumed came back, value for value.
        $values = $this->inTenant(fn () => Backflush::query()->get()->groupBy('kind')->map(fn ($group) => (int) BackflushLine::query()->whereIn('backflush_id', $group->pluck('id'))->sum('value_minor')));
        $this->assertSame($values['consume'], $values['return']);
        $this->assertSame(0, $this->inTenant(fn () => (int) Movement::query()->whereIn('source_type', ['manufacturing.backflush', 'manufacturing.backflush_return'])->sum('value_minor')));
    }

    public function test_a_void_gives_everything_back(): void
    {
        $sale = $this->sell(1, '3');
        $this->voidSale($sale);

        $this->assertSame(['PATTY' => '50', 'BUN' => '50', 'MAYO' => '10', 'CHILLI' => '2'], array_slice($this->stock(), 0, 4));
        $this->assertSame(['consume', 'return'], $this->inTenant(fn () => Backflush::query()->orderBy('kind')->pluck('kind')->all()));
        $this->assertSame(0, $this->inTenant(fn () => (int) Movement::query()->whereIn('source_type', ['manufacturing.backflush', 'manufacturing.backflush_return'])->sum('value_minor')));
    }

    public function test_a_refund_handled_before_its_sale_counts_once(): void
    {
        Event::fake([SaleCompleted::class]);
        $sale = $this->sell(1, '3');
        $this->refund($sale, '1', 1);
        $this->assertSame('50', $this->stock()['PATTY']);

        $event = new SaleCompleted($this->owner->tenant_id, $sale['id'], $this->acme->id, $this->locationA->id);
        app(BackflushSales::class)->handle($event);
        app(BackflushSales::class)->handle($event);

        $this->assertSame(['PATTY' => '48', 'BUN' => '48', 'MAYO' => '9.952', 'CHILLI' => '1.997'], array_slice($this->stock(), 0, 4));
        $this->assertSame(2, $this->inTenant(fn () => Backflush::query()->count()));
    }

    public function test_nothing_moves_unless_manufacturing_and_inventory_are_both_on(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));
        $this->sell(1, '3');
        $this->assertSame(0, $this->inTenant(fn () => Backflush::query()->count()));

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('manufacturing'));
        $this->sell(2, '3');
        $this->assertSame(0, $this->inTenant(fn () => Backflush::query()->count()));
        $this->assertSame('50', $this->stock()['PATTY']);
    }

    public function test_a_menu_item_made_a_stock_item_is_left_to_inventory(): void
    {
        // Decision 3: the item's type changed after its recipe: Inventory moves it; its recipe is skipped and said so.
        $this->inTenant(fn () => Item::query()->whereKey($this->burger->id)->update(['type' => 'stock']));
        $sale = $this->sell(1, '2');

        $this->assertSame(['PATTY' => '50', 'BURGER' => '-2'], ['PATTY' => $this->stock()['PATTY'], 'BURGER' => $this->stock()['BURGER']]);
        $backflush = $this->inTenant(fn () => Backflush::query()->where('source_id', $sale['id'])->sole());
        $this->assertEquals([['sale_line_id' => $sale['lines'][0]['id'], 'item_id' => $this->burger->id, 'code' => 'item_stocked']], $backflush->skipped);
    }

    /** Review fix 1: a void's stock moved but its values were lost once; the redelivered void records them, never zero. */
    public function test_a_redelivered_void_records_the_values_it_missed(): void
    {
        $sale = $this->sell(1, '3');
        $dispatcher = Event::getFacadeRoot();
        Event::fake([StockMoved::class, MaterialsMoved::class]);
        $this->voidSale($sale);
        $void = Event::dispatched(MaterialsMoved::class)->map(fn ($call) => $call[0])->first(fn (MaterialsMoved $e) => $e->kind === MaterialsMoved::BACKFLUSH_RETURN);
        // Inventory moves the stock back; Manufacturing's recording of the values fails (the event is lost).
        app(MoveStockForManufacturing::class)->handle($void);
        Event::swap($dispatcher);
        $this->assertSame('50', $this->stock()['PATTY']);
        $this->assertNull($this->inTenant(fn () => Backflush::query()->where('kind', Backflush::RETURN)->value('valued_at')));

        // The job is delivered again: nothing is left to move, and the values come back with the movements.
        app(MoveStockForManufacturing::class)->handle($void);

        $values = $this->inTenant(fn () => Backflush::query()->get()->groupBy('kind')->map(fn ($group) => (int) BackflushLine::query()->whereIn('backflush_id', $group->pluck('id'))->sum('value_minor')));
        $this->assertSame(54285, $values['return']);
        $this->assertSame($values['consume'], $values['return']);
        $this->assertSame('50', $this->stock()['PATTY']);
    }

    /** MFG-08: a void handled before its sale: the sale consumes nothing and nothing comes back. */
    public function test_a_void_handled_before_its_sale_moves_nothing(): void
    {
        Event::fake([SaleCompleted::class]);
        $sale = $this->sell(1, '3');
        $this->voidSale($sale);

        $event = new SaleCompleted($this->owner->tenant_id, $sale['id'], $this->acme->id, $this->locationA->id);
        app(BackflushSales::class)->handle($event);

        $this->assertSame(['PATTY' => '50', 'BUN' => '50', 'MAYO' => '10', 'CHILLI' => '2'], array_slice($this->stock(), 0, 4));
        $this->assertSame(0, $this->inTenant(fn () => Backflush::query()->count()));
    }

    /** MFG-07, INV-13: a sale is never refused for stock: ingredients go below zero, flagged. */
    public function test_consumption_beyond_the_stock_goes_negative_and_is_flagged(): void
    {
        $this->sell(1, '60');

        $this->assertSame('-10', $this->stock()['PATTY']);
        $patty = $this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.backflush')->where('item_id', $this->patty->id)->sole());
        $this->assertContains('negative_stock', $patty->flagCodes());
    }

    /** Review fix 2: an offline sale uploaded after a newer recipe version uses the version in force when it was sold. */
    public function test_an_offline_sale_uses_the_version_in_force_when_it_was_sold(): void
    {
        $bom = $this->getJson('/api/v1/manufacturing/boms?kind=recipe&item='.$this->burger->id, $this->headersFor())->assertOk()->json('data.0');
        $draft = $this->postJson("/api/v1/manufacturing/boms/{$bom['id']}/versions", [], $this->headersFor())->assertCreated()->json('data');
        $this->putJson("/api/v1/manufacturing/bom-versions/{$draft['id']}", ['output_qty' => '1', 'lines' => [
            ['item_id' => $this->patty->id, 'qty' => '2'], ['item_id' => $this->bun->id, 'qty' => '1'],
        ]], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/manufacturing/bom-versions/{$draft['id']}/publish", ['effective_from' => now()->subMinutes(7)->toIso8601String()], $this->headersFor())->assertOk();

        // Sold ten minutes ago (offline): version 1, one patty each.
        $sale = $this->saleBody($this->shift, 1, ['sold_at' => now()->subMinutes(10)->toIso8601String(), 'offline' => true, 'lines' => [
            $this->line(['item_id' => $this->burger->id, 'qty' => '1', 'tax_minor' => '6250', 'total_minor' => '56250']),
        ]]);
        $this->upload([$sale])->assertOk();
        $this->assertSame('49', $this->stock()['PATTY']);

        // Sold now: version 2, two patties.
        $this->sell(2, '1');
        $this->assertSame('47', $this->stock()['PATTY']);
    }

    /** Review fix 2: a recipe archived after a sale still applies to that sale, uploaded later. */
    public function test_a_recipe_archived_after_the_sale_still_applies_to_it(): void
    {
        $bom = $this->getJson('/api/v1/manufacturing/boms?kind=recipe&item='.$this->burger->id, $this->headersFor())->assertOk()->json('data.0');
        $this->travel(1)->minutes();
        $this->postJson("/api/v1/manufacturing/boms/{$bom['id']}/archive", [], $this->headersFor())->assertOk();

        $sale = $this->saleBody($this->shift, 1, ['sold_at' => now()->subMinutes(10)->toIso8601String(), 'offline' => true, 'lines' => [
            $this->line(['item_id' => $this->burger->id, 'qty' => '2', 'tax_minor' => '12500', 'total_minor' => '112500']),
        ]]);
        $this->upload([$sale])->assertOk();

        $this->assertSame(['PATTY' => '48', 'BUN' => '48'], array_slice($this->stock(), 0, 2));
    }
}
