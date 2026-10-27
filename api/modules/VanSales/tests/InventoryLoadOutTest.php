<?php

namespace Modules\VanSales\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Inventory\Contracts\StockLevels;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// VAN-07, VAN-08, VAN-16, VAN-23, INV-08: load-out and unload through
// Inventory's transfers (never its tables): requested by a supervisor,
// approved by a manager, released by a storekeeper, confirmed by the rep
// with a shortfall written off in transit; the unload received at the
// warehouse.
class InventoryLoadOutTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpVanSales(inventory: true);
        $this->inTenant(fn () => app(StockLedger::class)->post(new Posting($this->acme->id, 'test.source', $this->id(), CarbonImmutable::now(), null, [
            new PostingLine($this->soap->id, $this->locationA->id, Movement::RECEIPT, Qty::of('100'), cost: BigInteger::of(10000), setsLastCost: true),
        ], true)));
    }

    private function onHand(string $locationId): string
    {
        return $this->inTenant(fn () => app(StockLevels::class)->onHand($locationId, [$this->soap->id])[$this->soap->id]);
    }

    private function confirm(string $load, User $by, array $lines, bool $proof = true)
    {
        return $this->postJson("/api/v1/van-sales/device/loads/{$load}/confirm", [
            'user_id' => $by->id, 'lines' => $lines, ...($proof ? ['actor_proof' => $this->actorProof($by->id, $this->vanDevice)] : []),
        ], $this->tillHeaders($this->vanToken));
    }

    private function approveDocument(string $documentId, User $by): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $documentId)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($by))->assertOk();
    }

    public function test_a_load_goes_from_request_to_the_vans_stock(): void
    {
        $run = $this->startRun();
        $load = $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'run_id' => $run, 'lines' => [
            ['item_id' => $this->soap->id, 'qty' => '10'], ['item_id' => $this->soap->id, 'qty' => '2'],
        ]], $this->supervisor)->assertCreated()->assertJsonPath('data.status', 'awaiting_approval')->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.qty', '12');
        $id = $load->json('data.id');
        $transfer = $this->inTenant(fn () => Load::findOrFail($id)->transfer_id);
        $this->assertNotNull($transfer);
        $this->assertMatchesRegularExpression('/^TR-/', (string) $load->json('data.number'));

        $this->api('POST', "van-sales/loads/{$id}/release", [], $this->storekeeper)->assertConflict()->assertJsonPath('code', 'load_not_approved');
        $this->approveDocument($transfer, $this->manager);
        $this->api('GET', "van-sales/loads/{$id}", [], $this->supervisor)->assertOk()->assertJsonPath('data.status', 'approved');

        $this->api('POST', "van-sales/loads/{$id}/release", [], $this->rep)->assertForbidden();
        $this->api('POST', "van-sales/loads/{$id}/release", [], $this->storekeeper)->assertOk()->assertJsonPath('data.status', 'released');
        $this->assertSame(['88.000000', '0'], [$this->onHand($this->locationA->id), $this->onHand($this->vanLocation->id)]);

        // The rep counts 11 of 12. Nobody writes the missing one off on their word: the transfer arrives as sent and
        // the shortage is the day's reconciliation's to decide (VAN-08, VAN-21).
        $lineId = $load->json('data.lines.0.id');
        $this->confirm($id, $this->rep, [['id' => $lineId, 'qty_received' => '13']])->assertUnprocessable()->assertJsonPath('code', 'load_received_invalid');
        $this->confirm($id, $this->supervisor, [['id' => $lineId, 'qty_received' => '11']])->assertForbidden();
        $this->confirm($id, $this->rep, [['id' => $lineId, 'qty_received' => '11']], proof: false)->assertUnprocessable()->assertJsonPath('code', 'actor_unverified');
        $this->confirm($id, $this->rep, [['id' => $lineId, 'qty_received' => '11']])->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.lines.0.qty_confirmed', '11');
        $this->confirm($id, $this->rep, [['id' => $lineId, 'qty_received' => '11']])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('12.000000', $this->onHand($this->vanLocation->id));
        $this->inTenant(fn () => $this->assertSame(0, Movement::query()->where('kind', Movement::TRANSFER_LOSS)->count()));

        $this->api('POST', "van-sales/loads/{$id}/cancel", ['reason' => 'Late'], $this->supervisor)->assertConflict();
    }

    public function test_an_unapproved_load_can_be_cancelled_and_the_transfer_with_it(): void
    {
        $id = $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => [['item_id' => $this->soap->id, 'qty' => '5']]], $this->supervisor)
            ->assertCreated()->json('data.id');

        $this->api('POST', "van-sales/loads/{$id}/cancel", [], $this->supervisor)->assertUnprocessable();
        $this->api('POST', "van-sales/loads/{$id}/cancel", ['reason' => 'Wrong van'], $this->supervisor)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->confirm($id, $this->rep, [['id' => $this->id(), 'qty_received' => '1']])
            ->assertConflict()->assertJsonPath('code', 'load_not_released');
        $this->assertSame('100.000000', $this->onHand($this->locationA->id));
    }

    public function test_a_load_cannot_come_from_the_van_itself_or_a_stranger_or_for_a_stranger(): void
    {
        $line = [['item_id' => $this->soap->id, 'qty' => '5']];

        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->vanLocation->id, 'lines' => $line], $this->supervisor)->assertUnprocessable()->assertJsonPath('code', 'load_same_location');
        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => $line], $this->rep)->assertForbidden();
        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => []], $this->supervisor)->assertUnprocessable();
        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => [['item_id' => $this->id(), 'qty' => '1']]], $this->supervisor)->assertUnprocessable();
        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => [['item_id' => $this->soap->id, 'qty' => '0']]], $this->supervisor)->assertUnprocessable();

        $elsewhere = $this->userWith('route_supervisor', Scope::branch($this->branchB->id));
        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => $line], $elsewhere)->assertForbidden();
        $this->api('GET', 'van-sales/loads', [], $elsewhere)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_unload_goes_back_to_the_warehouse_after_the_count(): void
    {
        $run = $this->startRun();
        $transfer = $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'run_id' => $run, 'lines' => [['item_id' => $this->soap->id, 'qty' => '10']]], $this->supervisor)->assertCreated();
        $load = $transfer->json('data.id');
        $this->approveDocument($this->inTenant(fn () => Load::findOrFail($load)->transfer_id), $this->manager);
        $this->api('POST', "van-sales/loads/{$load}/release", [], $this->storekeeper)->assertOk();
        $this->confirm($load, $this->rep, [['id' => $transfer->json('data.lines.0.id'), 'qty_received' => '10']])->assertOk();

        $this->sellOnVan($this->vanSale($run, 1, null, [$this->pay('cash_kes', '337500')], '6'))->assertOk();
        $count = $this->countRecord($run, [['item_id' => $this->soap->id, 'returned_qty' => '4', 'damaged_qty' => '0']], [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '337500']]);
        $this->vsSync(['counts' => [$count]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'balanced');

        // Inventory does not know of the 6 sold until it handles the sale event; 4 are asked back, no more than the van holds.
        $reconciliation = $this->api('GET', "van-sales/reconciliations/{$count['id']}", [], $this->supervisor)->assertOk();
        $this->assertSame('awaiting_approval', $reconciliation->json('data.unload.status'));

        $unload = $this->inTenant(fn () => Count::findOrFail($count['id'])->unload_transfer_id);
        $this->api('POST', "van-sales/reconciliations/{$count['id']}/unload/receive", [], $this->storekeeper)->assertConflict()->assertJsonPath('code', 'unload_not_ready');
        $this->approveDocument($unload, $this->manager);
        $this->api('POST', "van-sales/reconciliations/{$count['id']}/unload/receive", [], $this->rep)->assertForbidden();
        $this->api('POST', "van-sales/reconciliations/{$count['id']}/unload/receive", ['lines' => [['item_id' => $this->id(), 'qty_received' => '1']]], $this->storekeeper)->assertUnprocessable();
        $this->api('POST', "van-sales/reconciliations/{$count['id']}/unload/receive", [], $this->storekeeper)->assertOk()->assertJsonPath('data.unload.status', 'received');
        $this->assertSame('94.000000', $this->onHand($this->locationA->id));
    }

    /** A load of $qty soaps through Inventory: requested, approved, released and confirmed (the rep counted $received). */
    private function loadThroughInventory(string $run, string $qty, ?string $received = null): string
    {
        $load = $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'run_id' => $run, 'lines' => [['item_id' => $this->soap->id, 'qty' => $qty]]], $this->supervisor)->assertCreated();
        $id = $load->json('data.id');
        $this->approveDocument($this->inTenant(fn () => Load::findOrFail($id)->transfer_id), $this->manager);
        $this->api('POST', "van-sales/loads/{$id}/release", [], $this->storekeeper)->assertOk();
        $this->confirm($id, $this->rep, [['id' => $load->json('data.lines.0.id'), 'qty_received' => $received ?? $qty]])->assertOk();

        return $id;
    }

    private function countAndSync(string $run, string $returned, array $tenders = []): array
    {
        $count = $this->countRecord($run, [['item_id' => $this->soap->id, 'returned_qty' => $returned, 'damaged_qty' => '0']], $tenders);
        $this->vsSync(['counts' => [$count]])->assertOk();

        return $count;
    }

    public function test_returns_above_what_inventory_shows_on_the_van_are_held_to_it_and_the_gap_is_a_variance(): void
    {
        $run = $this->startRun();
        $this->loadThroughInventory($run, '10');
        $this->sellOnVan($this->vanSale($run, 1, null, [$this->pay('cash_kes', '337500')], '6'))->assertOk();
        $this->assertSame('4.000000', $this->onHand($this->vanLocation->id));
        // Two are taken off the van in Inventory (an adjustment elsewhere); the rep still says the 4 came back.
        $this->inTenant(fn () => app(StockLedger::class)->post(new Posting($this->acme->id, 'test.source', $this->id(), CarbonImmutable::now(), null, [
            new PostingLine($this->soap->id, $this->vanLocation->id, Movement::ADJUSTMENT_OUT, Qty::of('-2')),
        ], true)));
        $count = $this->countAndSync($run, '4', [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '337500']]);

        $this->inTenant(function () use ($count) {
            $saved = Count::findOrFail($count['id']);
            $line = $saved->lines()->sole();
            $this->assertSame(['pending', ['returns_capped'], 2.0, 4.0, 2.0], [$saved->status, $saved->flags, (float) $line->returned_qty, (float) $line->returned_claimed_qty, (float) $line->variance_qty]);
        });
    }

    public function test_a_short_delivery_is_a_variance_for_an_approver_not_a_silent_write_off(): void
    {
        $run = $this->startRun();
        $this->loadThroughInventory($run, '12', '11');

        $count = $this->countAndSync($run, '11');

        $this->inTenant(function () use ($count) {
            $saved = Count::findOrFail($count['id']);
            $this->assertSame(['pending', 12.0, 1.0], [$saved->status, (float) $saved->lines()->sole()->load_qty, (float) $saved->lines()->sole()->variance_qty]);
        });
    }

    public function test_the_run_stays_counting_until_the_unload_is_received(): void
    {
        $run = $this->startRun();
        $this->loadThroughInventory($run, '10');
        $this->sellOnVan($this->vanSale($run, 1, null, [$this->pay('cash_kes', '337500')], '6'))->assertOk();
        $count = $this->countAndSync($run, '4', [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '337500']]);

        $this->inTenant(fn () => $this->assertSame(['balanced', 'counting'], [Count::findOrFail($count['id'])->status, Run::findOrFail($run)->status]));

        $unload = $this->inTenant(fn () => Count::findOrFail($count['id'])->unload_transfer_id);
        $this->approveDocument($unload, $this->manager);
        $this->inTenant(fn () => $this->assertSame('counting', Run::findOrFail($run)->status));
        $this->api('POST', "van-sales/reconciliations/{$count['id']}/unload/receive", [], $this->storekeeper)->assertOk();
        $this->inTenant(fn () => $this->assertSame('closed', Run::findOrFail($run)->status));
    }

    public function test_an_unload_found_short_goes_into_the_reconciliation_flow(): void
    {
        $run = $this->startRun();
        $this->loadThroughInventory($run, '10');
        $this->sellOnVan($this->vanSale($run, 1, null, [$this->pay('cash_kes', '337500')], '6'))->assertOk();
        $count = $this->countAndSync($run, '4', [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '337500']]);
        $unload = $this->inTenant(fn () => Count::findOrFail($count['id'])->unload_transfer_id);
        $this->approveDocument($unload, $this->manager);

        $this->api('POST', "van-sales/reconciliations/{$count['id']}/unload/receive", ['lines' => [['item_id' => $this->soap->id, 'qty_received' => '3']]], $this->storekeeper)->assertOk();

        $shortfall = $this->inTenant(fn () => Count::query()->where('run_id', $run)->where('kind', 'unload_shortfall')->sole());
        $this->assertSame(['pending', 1.0], [$shortfall->status, (float) $shortfall->lines()->sole()->variance_qty]);
        $this->inTenant(fn () => $this->assertSame('counting', Run::findOrFail($run)->status));

        $this->approveDocument($shortfall->id, $this->supervisor);
        $this->inTenant(fn () => $this->assertSame(['approved', 'closed'], [Count::findOrFail($shortfall->id)->status, Run::findOrFail($run)->status]));
    }
}
