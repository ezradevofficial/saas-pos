<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\Contracts\TransferLines;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Qty;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-06, PHA-12, PHA-13: Pharmacy and Inventory stay separate and agree
// through events: a back-office receipt and a write-off reach Inventory's
// ledger, Procurement's receipts reach the lots, and an Inventory transfer
// moves the lots first-expiry-first-out.
class InventoryIntegrationTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $manager;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPharmacy();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
    }

    private function inventoryQty(Item $item, ?string $location = null): string
    {
        return $this->inTenant(fn () => Qty::show((string) (Balance::query()->where('item_id', $item->id)->where('location_id', $location ?? $this->locationA->id)->value('qty') ?? '0')));
    }

    private function receiveViaPharmacy(string $batch, int $days, string $qty): void
    {
        $this->postJson('/api/v1/pharmacy/receipts', [
            'location_id' => $this->locationA->id,
            'lines' => [['item_id' => $this->amox->id, 'qty' => $qty, 'batch_number' => $batch, 'expires_on' => $this->inDays($days), 'unit_cost_minor' => '1000', 'currency' => 'KES']],
        ], $this->headersFor($this->technician))->assertCreated();
    }

    private function approve(string $documentId): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $documentId)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->manager))->assertOk();
    }

    public function test_a_back_office_receipt_and_a_write_off_reach_inventorys_ledger_at_cost(): void
    {
        $this->receiveViaPharmacy('B-1', 40, '10');

        $this->assertSame('10', $this->inventoryQty($this->amox));
        $receipt = $this->inTenant(fn () => Movement::query()->where('item_id', $this->amox->id)->where('kind', Movement::RECEIPT)->sole());
        $this->assertSame(['pharmacy.receipt', '10000', 'KES'], [$receipt->source_type === 'procurement.goods_receipt' ? 'pharmacy.receipt' : $receipt->source_type, (string) $receipt->value_minor, $receipt->currency]);
        // Pharmacy kept the lot once, whoever told it: its own receipt is not entered twice.
        $this->assertSame(1, $this->inTenant(fn () => LotMovement::query()->where('kind', 'receipt')->count()));

        $lot = $this->inTenant(fn () => Lot::sole());
        $writeOff = $this->postJson('/api/v1/pharmacy/write-offs', ['location_id' => $this->locationA->id, 'reason' => 'damaged', 'lines' => [['lot_id' => $lot->id, 'qty' => '3']]], $this->headersFor($this->technician))->assertCreated()->json('data.id');
        $this->approve($writeOff);

        $this->assertSame('7', $this->inventoryQty($this->amox));
        $out = $this->inTenant(fn () => Movement::query()->where('source_type', 'pharmacy.write_off')->sole());
        $this->assertSame([Movement::ADJUSTMENT_OUT, '-3.000000', '-3000', $writeOff], [$out->kind, (string) $out->qty, (string) $out->value_minor, $out->source_id]);
        $this->assertSame('7', $this->lotQty($lot));
    }

    public function test_a_write_off_with_inventory_off_only_touches_the_lots(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));
        $lot = $this->lot($this->amox, 'OLD', -1, '5');
        $writeOff = $this->postJson('/api/v1/pharmacy/write-offs', ['location_id' => $this->locationA->id, 'reason' => 'expired', 'lines' => [['lot_id' => $lot->id, 'qty' => '5']]], $this->headersFor($this->technician))->assertCreated()->json('data.id');
        $this->approve($writeOff);

        $this->assertSame('0', $this->lotQty($lot));
        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->count()));
    }

    public function test_goods_received_by_another_module_enter_the_lots_of_tracked_items_once(): void
    {
        $receiptId = (string) Str::uuid7();
        $event = new GoodsReceived($this->owner->tenant_id, $receiptId, $this->acme->id, $this->locationA->id, now()->toIso8601String(), 'GRN-9', [
            ['line_id' => $a = (string) Str::uuid7(), 'item_id' => $this->amox->id, 'qty' => '20', 'cost_minor' => '20000', 'currency' => 'KES', 'batch_number' => 'GRN-B1', 'expires_on' => $this->inDays(365)],
            ['line_id' => (string) Str::uuid7(), 'item_id' => $this->amox->id, 'qty' => '5', 'cost_minor' => '5000', 'currency' => 'KES'],
            ['line_id' => (string) Str::uuid7(), 'item_id' => $this->plain->id, 'qty' => '5', 'cost_minor' => '5000', 'currency' => 'KES', 'batch_number' => 'X', 'expires_on' => $this->inDays(365)],
            ['line_id' => (string) Str::uuid7(), 'item_id' => $this->morphine->id, 'uom_id' => $this->each->id, 'qty' => '3', 'cost_minor' => '9000', 'currency' => 'KES', 'batch_number' => 'M-GRN', 'expires_on' => $this->inDays(365)],
        ], $this->owner->id);

        $this->inTenant(fn () => event($event));
        $this->inTenant(fn () => event($event));

        // Only tracked items that name a batch and an expiry enter lots; the rest stay Inventory's alone.
        $this->assertSame(['GRN-B1', 'M-GRN'], $this->inTenant(fn () => Lot::query()->orderBy('batch_number')->pluck('batch_number')->all()));
        $this->assertSame('20', $this->lotQty($this->inTenant(fn () => Lot::query()->where('batch_number', 'GRN-B1')->sole())));
        $this->assertSame(2, $this->inTenant(fn () => LotMovement::query()->where('source_type', 'procurement.goods_receipt')->count()));
        $this->assertSame('25', $this->inventoryQty($this->amox));
        // The controlled item is also in the register.
        $this->assertSame(1, $this->inTenant(fn () => RegisterEntry::query()->where('item_id', $this->morphine->id)->where('kind', 'receipt')->count()));
        $this->assertNotNull($a);
    }

    public function test_an_inventory_transfer_moves_the_lots_first_expiry_first_and_writes_off_what_was_lost(): void
    {
        $this->receiveViaPharmacy('SOON', 60, '4');
        $this->receiveViaPharmacy('LATE', 300, '10');
        $keeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));

        $transfer = $this->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
            'lines' => [['item_id' => $this->amox->id, 'qty' => '6']],
        ], $this->headersFor($keeper))->assertCreated()->json('data');
        $this->approve($transfer['id']);
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch", [], $this->headersFor($keeper))->assertOk();
        $receiver = $this->userWith('storekeeper', Scope::location($this->locationB->id));
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receive", ['lines' => [['id' => $transfer['lines'][0]['id'], 'qty_received' => '5']], 'loss_reason' => 'Crushed box'], $this->headersFor($receiver))
            ->assertOk()->assertJsonPath('data.status', 'received');

        $soon = $this->inTenant(fn () => Lot::query()->where('batch_number', 'SOON')->sole());
        $late = $this->inTenant(fn () => Lot::query()->where('batch_number', 'LATE')->sole());
        // Six left A (the 4 of the first expiry, then 2), five arrived at B (4, then 1); one was lost.
        $this->assertSame(['0', '8'], [$this->lotQty($soon), $this->lotQty($late)]);
        $this->assertSame(['4', '1'], [$this->lotQty($soon, $this->locationB->id), $this->lotQty($late, $this->locationB->id)]);
        $this->assertSame('8', $this->inventoryQty($this->amox));
        $this->assertSame('5', $this->inventoryQty($this->amox, $this->locationB->id));

        // The contract tells a listener what the transfer moved, in base units.
        $lines = $this->inTenant(fn () => TransferLines::forTransfer($transfer['id']));
        $this->assertSame([$this->locationA->id, $this->locationB->id, '6.000000', '5.000000'], [$lines['from_location_id'], $lines['to_location_id'], $lines['lines'][0]['qty_sent'], $lines['lines'][0]['qty_received']]);
        $this->assertNull($this->inTenant(fn () => TransferLines::forTransfer((string) Str::uuid7())));
    }
}
