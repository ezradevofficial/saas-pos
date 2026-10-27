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

        // The rep counts 11 of 12: one is lost in transit.
        $lineId = $load->json('data.lines.0.id');
        $this->postJson("/api/v1/van-sales/device/loads/{$id}/confirm", ['user_id' => $this->rep->id, 'lines' => [['id' => $lineId, 'qty_received' => '13']]], $this->tillHeaders($this->vanToken))
            ->assertUnprocessable()->assertJsonPath('code', 'load_received_invalid');
        $this->postJson("/api/v1/van-sales/device/loads/{$id}/confirm", ['user_id' => $this->supervisor->id, 'lines' => [['id' => $lineId, 'qty_received' => '11']]], $this->tillHeaders($this->vanToken))
            ->assertForbidden();
        $this->postJson("/api/v1/van-sales/device/loads/{$id}/confirm", ['user_id' => $this->rep->id, 'lines' => [['id' => $lineId, 'qty_received' => '11']]], $this->tillHeaders($this->vanToken))
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.lines.0.qty_confirmed', '11');
        $this->postJson("/api/v1/van-sales/device/loads/{$id}/confirm", ['user_id' => $this->rep->id, 'lines' => [['id' => $lineId, 'qty_received' => '11']]], $this->tillHeaders($this->vanToken))
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('11.000000', $this->onHand($this->vanLocation->id));

        $this->api('POST', "van-sales/loads/{$id}/cancel", ['reason' => 'Late'], $this->supervisor)->assertConflict();
    }

    public function test_an_unapproved_load_can_be_cancelled_and_the_transfer_with_it(): void
    {
        $id = $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => [['item_id' => $this->soap->id, 'qty' => '5']]], $this->supervisor)
            ->assertCreated()->json('data.id');

        $this->api('POST', "van-sales/loads/{$id}/cancel", [], $this->supervisor)->assertUnprocessable();
        $this->api('POST', "van-sales/loads/{$id}/cancel", ['reason' => 'Wrong van'], $this->supervisor)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/van-sales/device/loads/{$id}/confirm", ['user_id' => $this->rep->id, 'lines' => [['id' => $this->id(), 'qty_received' => '1']]], $this->tillHeaders($this->vanToken))
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
        $this->postJson("/api/v1/van-sales/device/loads/{$load}/confirm", ['user_id' => $this->rep->id, 'lines' => [['id' => $transfer->json('data.lines.0.id'), 'qty_received' => '10']]], $this->tillHeaders($this->vanToken))->assertOk();

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
}
