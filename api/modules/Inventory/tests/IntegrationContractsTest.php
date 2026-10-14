<?php

namespace Modules\Inventory\Tests;

use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\TenantCurrencies;
use App\Core\Http\ApiException;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\Events\StockMoved;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Serial;
use Modules\Inventory\Stock\OpeningStock;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// INV-15, INV-16, INV-18, IMP-01, IMP-02: goods received from another
// module (the Procurement contract), StockMoved with values, and opening
// stock imported from a file and undone.
class IntegrationContractsTest extends TestCase
{
    use BuildsImports, BuildsInventory, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInventory();
    }

    private function receipt(string $id, array $lines): GoodsReceived
    {
        return new GoodsReceived($this->owner->tenant_id, $id, $this->acme->id, $this->locationA->id, now()->toIso8601String(), 'GRN-0001', $lines);
    }

    public function test_goods_received_enter_stock_once_at_their_cost_with_batches_and_serials(): void
    {
        Event::fake([StockMoved::class]);
        $id = (string) Str::uuid7();
        $event = $this->receipt($id, [
            ['line_id' => (string) Str::uuid7(), 'item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '2', 'cost_minor' => '48000', 'currency' => 'KES'],
            ['line_id' => (string) Str::uuid7(), 'item_id' => $this->milk->id, 'qty' => '6', 'cost_minor' => '1200', 'currency' => 'KES', 'batch_number' => 'LOT-9', 'expires_on' => '2027-02-01'],
            ['line_id' => (string) Str::uuid7(), 'item_id' => $this->phone->id, 'qty' => '2', 'cost_minor' => '2000000', 'currency' => 'KES', 'serials' => ['IMEI-1', 'IMEI-2']],
        ]);

        $this->inTenant(fn () => GoodsReceived::dispatch(...[$event->tenantId, $event->receiptId, $event->companyId, $event->locationId, $event->receivedAt, $event->reference, $event->lines]));
        $this->inTenant(fn () => GoodsReceived::dispatch(...[$event->tenantId, $event->receiptId, $event->companyId, $event->locationId, $event->receivedAt, $event->reference, $event->lines]));

        $this->assertSame(['24', '6', '2'], [$this->onHand($this->soap), $this->onHand($this->milk), $this->onHand($this->phone)]);
        $this->assertSame(['qty' => '24', 'value' => '48000'], $this->costState($this->soap));
        $this->assertSame(2, $this->inTenant(fn () => Serial::query()->where('status', 'in_stock')->count()));
        $this->assertSame(3, $this->inTenant(fn () => Movement::query()->where('source_type', 'procurement.goods_receipt')->count()));
        Event::assertDispatched(StockMoved::class, fn (StockMoved $e) => $e->sourceId === $id && count($e->movements) === 3 && $e->movements[0]['value_minor'] === '48000');
    }

    public function test_a_cost_in_another_currency_is_converted_at_the_company_rate(): void
    {
        $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            ExchangeRate::create(['company_id' => $this->acme->id, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop', 'mid' => '130', 'effective_at' => now()->subDay(), 'source' => 'manual']);
        });
        $event = $this->receipt((string) Str::uuid7(), [['line_id' => (string) Str::uuid7(), 'item_id' => $this->soap->id, 'qty' => '10', 'cost_minor' => '1000', 'currency' => 'USD']]);

        $this->inTenant(fn () => GoodsReceived::dispatch($event->tenantId, $event->receiptId, $event->companyId, $event->locationId, $event->receivedAt, $event->reference, $event->lines));

        $this->assertSame(['qty' => '10', 'value' => '130000'], $this->costState($this->soap));
    }

    public function test_with_inventory_off_goods_received_move_nothing(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));
        $event = $this->receipt((string) Str::uuid7(), [['line_id' => (string) Str::uuid7(), 'item_id' => $this->soap->id, 'qty' => '1', 'cost_minor' => '100', 'currency' => 'KES']]);

        $this->inTenant(fn () => GoodsReceived::dispatch($event->tenantId, $event->receiptId, $event->companyId, $event->locationId, $event->receivedAt, $event->reference, $event->lines));

        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->count()));
    }

    public function test_an_opening_reversal_is_refused_under_the_lock_once_the_item_moved(): void
    {
        $opening = $this->inTenant(fn () => app(OpeningStock::class)->record(['item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'qty' => '5', 'unit_cost' => '1'], $this->owner));
        $this->move($this->soap, Movement::SALE, '-1', manual: false);

        // Straight to the ledger, as a reversal racing the sale would arrive after its own check.
        try {
            $this->move($this->soap, Movement::OPENING_REVERSAL, '-5', extra: ['reverses' => $opening->id, 'source_type' => 'inventory.opening'], source: $opening->source_id);
            $this->fail('The opening was reversed after the item moved.');
        } catch (ApiException $e) {
            $this->assertSame('opening_used', $e->errorCode);
        }

        $this->assertSame('4', $this->onHand($this->soap));
    }

    public function test_an_opening_that_covered_negative_stock_is_not_undone(): void
    {
        $this->move($this->soap, Movement::SALE, '-3', manual: false);
        $opening = $this->inTenant(fn () => app(OpeningStock::class)->record(['item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'qty' => '10', 'unit_cost' => '1'], $this->owner));
        $this->assertNotSame('0', (string) $opening->cost_variance_minor);

        try {
            $this->inTenant(fn () => app(OpeningStock::class)->reverse($opening, $this->owner));
            $this->fail('An opening with a cost variance was reversed.');
        } catch (ApiException $e) {
            $this->assertSame([409, 'opening_has_variance'], [$e->getStatusCode(), $e->errorCode]);
        }

        $this->assertSame('7', $this->onHand($this->soap));
    }

    public function test_opening_stock_is_imported_and_undone_until_stock_moves(): void
    {
        Storage::fake('media');
        $this->inTenant(fn () => $this->locationA->forceFill(['code' => 'L01'])->save());
        $headers = ['Item code', 'Location', 'Quantity', 'Unit', 'Unit cost', 'Batch number', 'Expiry date'];

        $import = $this->importFile('opening_stock', $this->csvFile([$headers,
            ['SOAP', 'L01', '2', 'BOX', '100', '', ''],
            ['MILK', 'Outlet A', '5', '', '1.5', 'LOT-1', '2027-05-01'],
            ['NOPE', 'L01', '1', '', '1', '', ''],
            ['MILK', 'L01', '5', '', '1', '', ''],
        ]));

        $this->assertSame([2, 2], [$import['created_rows'], $import['failed_rows']]);
        $this->assertSame(['24', '5'], [$this->onHand($this->soap), $this->onHand($this->milk)]);
        $this->assertSame(['qty' => '24', 'value' => '20000'], $this->costState($this->soap));

        // Once soap sells, the import can no longer be undone.
        $this->move($this->soap, Movement::SALE, '-1', manual: false);
        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'import_undo_blocked');

        $second = $this->importFile('opening_stock', $this->csvFile([$headers, ['SUGAR', 'L01', '2.5', '', '80', '', '']]));
        $this->postJson("/api/v1/imports/{$second['id']}/undo", [], $this->headersFor())->assertStatus(202);
        $this->assertSame('0', $this->onHand($this->sugar));
        $this->assertSame(['qty' => '0', 'value' => '0'], $this->costState($this->sugar));
        $this->assertSame(1, $this->inTenant(fn () => Movement::query()->where('kind', 'opening_reversal')->count()));
    }
}
