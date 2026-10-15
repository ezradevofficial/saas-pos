<?php

namespace Modules\Inventory\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\StockCosts;
use Modules\Inventory\Events\StockMoved;
use Modules\Inventory\Listeners\MoveStockForManufacturing;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use Modules\Manufacturing\Events\MaterialsMoved;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MFG-15 (docs/modules/manufacturing.md), INV-04, INV-06, INV-12:
// Manufacturing's documents move stock through Inventory's ledger: issues
// and recipe consumption go out (batches earliest expiry first, never
// refused), returns come back from their origin's movements at its cost,
// receipts come in at their cost; each document posts once. StockCosts
// tells other modules the current unit cost.
class ManufacturingIntegrationTest extends TestCase
{
    use BuildsInventory, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    private function event(string $kind, string $documentId, array $lines, ?string $originId = null): MaterialsMoved
    {
        return new MaterialsMoved($this->owner->tenant_id, $kind, $documentId, 'MI-0001', $this->acme->id, $this->branchA->id, $this->locationA->id,
            CarbonImmutable::now()->toIso8601String(), null, $lines, $originId);
    }

    private function line(string $itemId, string $qty, array $extra = []): array
    {
        return ['line_id' => (string) Str::uuid7(), 'item_id' => $itemId, 'uom_id' => $this->each->id, 'qty' => $qty, 'qty_base' => $qty, ...$extra];
    }

    public function test_an_issue_goes_out_once_earliest_expiry_first_and_a_return_comes_back_at_its_cost(): void
    {
        [$early, $late] = $this->inTenant(fn () => [
            Batch::create(['item_id' => $this->milk->id, 'batch_number' => 'B1', 'expires_on' => now()->addDays(3)->toDateString()]),
            Batch::create(['item_id' => $this->milk->id, 'batch_number' => 'B2', 'expires_on' => now()->addDays(9)->toDateString()]),
        ]);
        $this->move($this->milk, 'receipt', '5', 5000, extra: ['batch_id' => $late->id]);
        $this->move($this->milk, 'receipt', '5', 6000, extra: ['batch_id' => $early->id]);
        $this->move($this->soap, 'receipt', '10', 10000);

        $issueId = (string) Str::uuid7();
        $milk = $this->line($this->milk->id, '7');
        $soap = $this->line($this->soap->id, '12');
        $issue = $this->event(MaterialsMoved::ISSUE, $issueId, [$milk, $soap]);
        app(MoveStockForManufacturing::class)->handle($issue);
        app(MoveStockForManufacturing::class)->handle($issue);

        $out = $this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.issue')->orderBy('created_at')->orderBy('id')->get());
        $this->assertSame(['production_issue'], $out->pluck('kind')->unique()->values()->all());
        // Earliest expiry first (B1 5, then B2 2); the soap goes 2 below zero, never refused.
        $this->assertSame([[$early->id, '-5'], [$late->id, '-2']], $out->where('item_id', $this->milk->id)->map(fn ($m) => [$m->batch_id, Qty::show($m->qty)])->values()->all());
        $this->assertSame('-2', $this->onHand($this->soap));
        $this->assertContains('negative_stock', $out->firstWhere('item_id', $this->soap->id)->flagCodes());

        // Half the soap comes back at what it left at.
        $soapOut = $out->firstWhere('item_id', $this->soap->id);
        $return = $this->event(MaterialsMoved::RETURN, (string) Str::uuid7(), [$this->line($this->soap->id, '6', ['origin_line_id' => $soap['line_id']])], $issueId);
        app(MoveStockForManufacturing::class)->handle($return);
        $back = $this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.return')->sole());
        $this->assertSame(['production_return', '6', (string) intdiv(abs((int) $soapOut->value_minor), 2)], [$back->kind, Qty::show($back->qty), (string) $back->value_minor]);
    }

    public function test_a_return_handled_before_its_issue_waits(): void
    {
        $this->expectException(RuntimeException::class);

        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->move([
            'kind' => MaterialsMoved::RETURN, 'id' => (string) Str::uuid7(), 'number' => 'MR-1', 'company_id' => $this->acme->id, 'location_id' => $this->locationA->id,
            'at' => now()->toIso8601String(), 'user_id' => null, 'origin_id' => (string) Str::uuid7(),
            'lines' => [$this->line($this->soap->id, '1', ['origin_line_id' => (string) Str::uuid7()])],
        ]));
    }

    public function test_a_receipt_comes_in_at_its_cost_and_a_redelivery_raises_its_values_again(): void
    {
        $receipt = $this->event(MaterialsMoved::RECEIPT, (string) Str::uuid7(), [$this->line($this->soap->id, '4', ['cost_minor' => '10001'])]);
        app(MoveStockForManufacturing::class)->handle($receipt);

        $in = $this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.receipt')->sole());
        $this->assertSame(['production_receipt', '10001'], [$in->kind, (string) $in->value_minor]);
        $this->assertSame(['qty' => '4', 'value' => '10001'], $this->costState($this->soap));
        $this->assertSame(['unit_cost_minor' => '2500.250000', 'currency' => 'KES'], $this->inTenant(fn () => app(StockCosts::class)->unitCosts($this->acme->id, [$this->soap->id])[$this->soap->id]));

        Event::fake([StockMoved::class]);
        app(MoveStockForManufacturing::class)->handle($receipt);
        Event::assertDispatched(StockMoved::class, fn (StockMoved $e) => $e->sourceType === 'manufacturing.receipt' && $e->movements[0]['value_minor'] === '10001');
        $this->assertSame(1, $this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.receipt')->count()));
    }

    public function test_a_redelivered_full_return_raises_what_it_came_back_at(): void
    {
        // Review fix 1: everything issued came back; the return's event comes again (its value was
        // missed). Nothing is left to take, yet the values raised are the ones it posted, not nothing.
        $this->move($this->soap, 'receipt', '10', 10000);
        $issueId = (string) Str::uuid7();
        $soap = $this->line($this->soap->id, '4');
        app(MoveStockForManufacturing::class)->handle($this->event(MaterialsMoved::ISSUE, $issueId, [$soap]));
        $return = $this->event(MaterialsMoved::RETURN, (string) Str::uuid7(), [$this->line($this->soap->id, '4', ['origin_line_id' => $soap['line_id']])], $issueId);
        app(MoveStockForManufacturing::class)->handle($return);
        $back = $this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.return')->sole());
        $this->assertSame('4000', (string) $back->value_minor);

        Event::fake([StockMoved::class]);
        app(MoveStockForManufacturing::class)->handle($return);

        Event::assertDispatched(StockMoved::class, fn (StockMoved $e) => $e->sourceType === 'manufacturing.return' && count($e->movements) === 1
            && $e->movements[0]['value_minor'] === '4000' && $e->movements[0]['source_line_id'] === $return->lines[0]['line_id']);
        $this->assertSame(1, $this->inTenant(fn () => Movement::query()->where('source_type', 'manufacturing.return')->count()));
        $this->assertSame('10', $this->onHand($this->soap));
    }
}
