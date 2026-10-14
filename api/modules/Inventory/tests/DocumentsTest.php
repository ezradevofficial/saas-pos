<?php

namespace Modules\Inventory\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Documents\Adjustments;
use Modules\Inventory\Documents\TransferType;
use Modules\Inventory\Events\StockAdjusted;
use Modules\Inventory\Events\StockCountPosted;
use Modules\Inventory\Events\TransferReceived;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Serial;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// INV-08, INV-09, INV-10, INV-16, INV-23, INV-24, WF-01, APR-07: transfers,
// adjustments and counts through their flows, approved by a Branch
// Manager, with permission and other-tenant refusals.
class DocumentsTest extends TestCase
{
    use BuildsInventory, RefreshTenantDatabase;

    private User $manager;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpInventory();
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $this->move($this->soap, Movement::RECEIPT, '100', 10000);
    }

    private function approve(string $documentId, ?User $by = null): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $documentId)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($by ?? $this->manager))->assertOk();
    }

    private function reject(string $documentId): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $documentId)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/reject", ['comment' => 'No'], $this->headersFor($this->manager))->assertOk();
    }

    public function test_a_transfer_is_approved_dispatched_and_received_with_a_loss(): void
    {
        Event::fake([TransferReceived::class]);

        $transfer = $this->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
            'lines' => [['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '2']],
        ], $this->headersFor($this->storekeeper))->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.lines.0.qty_base', '24')
            ->assertJsonPath('data.value', null)
            ->json('data');
        // Storekeepers see quantities, not costs (RBAC-05).
        $this->getJson("/api/v1/inventory/transfers/{$transfer['id']}", $this->headersFor())->assertJsonPath('data.value', ['amount_minor' => '2400', 'currency' => 'KES']);

        $this->assertMatchesRegularExpression('/^TR-A-\d{4}-00001$/', $transfer['number']);

        // Not dispatchable before approval; the storekeeper may not approve.
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch", [], $this->headersFor($this->storekeeper))->assertStatus(409);
        $this->approve($transfer['id']);
        $this->getJson("/api/v1/inventory/transfers/{$transfer['id']}", $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.can.dispatch', true);

        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch", [], $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame(['76', '0'], [$this->onHand($this->soap), $this->onHand($this->soap, $this->locationB->id)]);

        // The storekeeper of A may not receive at B; the branch manager of A neither.
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receive", [], $this->headersFor($this->storekeeper))->assertForbidden();
        $receiver = $this->userWith('storekeeper', Scope::location($this->locationB->id));
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receive", [
            'lines' => [['id' => $transfer['lines'][0]['id'], 'qty_received' => '1.5']], 'loss_reason' => 'Crushed box',
        ], $this->headersFor($receiver))->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.lines.0.qty_received', '1.5')
            ->assertJsonPath('data.lines.0.qty_lost', '0.5');

        $this->assertSame('18', $this->onHand($this->soap, $this->locationB->id));
        // The 6 lost leave the company's stock at their cost; the 18 that arrived keep theirs.
        $this->assertSame(['qty' => '94', 'value' => '9400'], $this->costState($this->soap));
        Event::assertDispatched(TransferReceived::class, fn (TransferReceived $e) => $e->transferId === $transfer['id'] && $e->receivedMinor === '1800' && $e->lostMinor === '600');
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'inventory.transfer.update')->exists()));
    }

    public function test_what_arrives_and_what_is_lost_share_exactly_what_left_even_when_cost_moves_in_between(): void
    {
        $transfer = $this->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
            'lines' => [['item_id' => $this->soap->id, 'qty' => '7']],
        ], $this->headersFor($this->storekeeper))->assertCreated()->json('data');
        $this->approve($transfer['id']);
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch", [], $this->headersFor())->assertOk();

        // A dearer receipt moves the company's average while the goods travel.
        $this->move($this->soap, Movement::RECEIPT, '30', 9000);
        $receiver = $this->userWith('storekeeper', Scope::location($this->locationB->id));
        $this->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receive", [
            'lines' => [['id' => $transfer['lines'][0]['id'], 'qty_received' => '4']], 'loss_reason' => 'Broken',
        ], $this->headersFor($receiver))->assertOk();

        $values = $this->inTenant(fn () => Movement::query()->where('source_id', $transfer['id'])->pluck('value_minor', 'kind')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(-700, $values['transfer_out']);
        $this->assertSame(700, $values['transfer_in'] - $values['transfer_loss']);
        $this->assertSame(400, $values['transfer_in']);
    }

    public function test_transfers_between_companies_or_to_the_same_place_are_refused(): void
    {
        $headers = $this->headersFor();
        $line = [['item_id' => $this->soap->id, 'qty' => '1']];
        $other = $this->inTenant(fn () => $this->location($this->branch($this->company('Other co'), 'O'), 'Other outlet'));

        $this->postJson('/api/v1/inventory/transfers', ['from_location_id' => $this->locationA->id, 'to_location_id' => $other->id, 'lines' => $line], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'transfer_cross_company');
        $this->postJson('/api/v1/inventory/transfers', ['from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationA->id, 'lines' => $line], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'same_location');
        $this->postJson('/api/v1/inventory/transfers', ['from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id, 'lines' => [['item_id' => $this->service->id, 'qty' => '1']]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'not_a_stock_item');
    }

    public function test_a_rejected_transfer_and_a_cancelled_one_move_nothing(): void
    {
        $body = ['from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id, 'lines' => [['item_id' => $this->soap->id, 'qty' => '5']]];
        $rejected = $this->postJson('/api/v1/inventory/transfers', $body, $this->headersFor($this->storekeeper))->assertCreated()->json('data.id');
        $this->reject($rejected);
        $this->getJson("/api/v1/inventory/transfers/{$rejected}", $this->headersFor())->assertJsonPath('data.status', 'rejected');

        $cancelled = $this->postJson('/api/v1/inventory/transfers', $body, $this->headersFor($this->storekeeper))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/transfers/{$cancelled}/cancel", ['reason' => 'Not needed'], $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('100', $this->onHand($this->soap));
    }

    public function test_an_adjustment_posts_on_approval_with_its_reason_and_values(): void
    {
        Event::fake([StockAdjusted::class]);

        $adjustment = $this->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $this->locationA->id, 'reason' => 'damage', 'note' => 'Wet carton',
            'lines' => [
                ['item_id' => $this->soap->id, 'qty' => '-3'],
                ['item_id' => $this->milk->id, 'qty' => '10', 'batch_number' => 'LOT-1', 'expires_on' => '2027-01-31', 'unit_cost' => '2.50'],
            ],
        ], $this->headersFor($this->storekeeper))->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.value_out', null)
            ->json('data');
        $this->getJson("/api/v1/inventory/adjustments/{$adjustment['id']}", $this->headersFor())
            ->assertJsonPath('data.value_out', ['amount_minor' => '300', 'currency' => 'KES'])
            ->assertJsonPath('data.value_in', ['amount_minor' => '2500', 'currency' => 'KES']);

        // APR-07: the requester never approves their own request.
        $this->assertSame('100', $this->onHand($this->soap));
        $this->approve($adjustment['id']);

        $this->getJson("/api/v1/inventory/adjustments/{$adjustment['id']}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.lines.0.value', ['amount_minor' => '-300', 'currency' => 'KES']);
        $this->assertSame(['97', '10'], [$this->onHand($this->soap), $this->onHand($this->milk)]);
        Event::assertDispatched(StockAdjusted::class, fn (StockAdjusted $e) => $e->valueOutMinor === '300' && $e->valueInMinor === '2500' && $e->reason === 'damage');
    }

    public function test_an_approved_adjustment_that_stock_no_longer_allows_is_marked_not_posted(): void
    {
        $this->costing(InventorySetting::WEIGHTED_AVERAGE, allowNegative: false);
        $adjustment = $this->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $this->locationA->id, 'reason' => 'theft', 'lines' => [['item_id' => $this->soap->id, 'qty' => '-90']],
        ], $this->headersFor($this->storekeeper))->assertCreated()->json('data.id');
        // Meanwhile a sale takes most of the stock.
        $this->move($this->soap, Movement::SALE, '-20', manual: false);

        $this->approve($adjustment);

        $this->getJson("/api/v1/inventory/adjustments/{$adjustment}", $this->headersFor())
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure', 'insufficient_stock');
        $this->assertSame('80', $this->onHand($this->soap));
    }

    public function test_serial_and_batch_lines_need_their_serials_and_batches(): void
    {
        $headers = $this->headersFor();
        $this->postJson('/api/v1/inventory/adjustments', ['location_id' => $this->locationA->id, 'reason' => 'found', 'lines' => [['item_id' => $this->phone->id, 'qty' => '2', 'serials' => ['SN-1']]]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'serials_required');
        $this->postJson('/api/v1/inventory/adjustments', ['location_id' => $this->locationA->id, 'reason' => 'found', 'lines' => [['item_id' => $this->milk->id, 'qty' => '2']]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'batch_required');
        $this->postJson('/api/v1/inventory/adjustments', ['location_id' => $this->locationA->id, 'reason' => 'found', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1.5']]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'qty_precision');

        $id = $this->postJson('/api/v1/inventory/adjustments', ['location_id' => $this->locationA->id, 'reason' => 'found', 'lines' => [['item_id' => $this->phone->id, 'qty' => '2', 'serials' => ['SN-1', 'SN-2']]]], $headers)
            ->assertCreated()->json('data.id');
        $this->approve($id);
        $this->assertSame(2, $this->inTenant(fn () => Serial::query()->where('status', Serial::IN_STOCK)->where('location_id', $this->locationA->id)->count()));

        // Going out, a serial must be in stock here.
        $this->postJson('/api/v1/inventory/adjustments', ['location_id' => $this->locationB->id, 'reason' => 'theft', 'lines' => [['item_id' => $this->phone->id, 'qty' => '-1', 'serials' => ['SN-1']]]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'serial_not_in_stock');
    }

    public function test_a_count_freezes_its_items_and_posts_the_approved_variance(): void
    {
        Event::fake([StockCountPosted::class]);

        $count = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->locationA->id, 'kind' => 'cycle', 'item_ids' => [$this->soap->id]], $this->headersFor($this->storekeeper))
            ->assertCreated()->assertJsonPath('data.status', 'counting')->assertJsonPath('data.lines.0.expected_qty', '100')->json('data');

        // INV-09: frozen for manual moves, not for sales (flagged).
        $this->postJson('/api/v1/inventory/adjustments', ['location_id' => $this->locationA->id, 'reason' => 'found', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'count_in_progress');
        [$sale] = $this->move($this->soap, Movement::SALE, '-2', manual: false);
        $this->assertContains('during_count', $sale->flagCodes());
        $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->locationA->id, 'kind' => 'full'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'count_in_progress');

        $this->postJson("/api/v1/inventory/counts/{$count['id']}/submit", [], $this->headersFor($this->storekeeper))->assertUnprocessable()->assertJsonPath('code', 'count_incomplete');
        $this->putJson("/api/v1/inventory/counts/{$count['id']}/lines", ['lines' => [['id' => $count['lines'][0]['id'], 'counted_qty' => '95']]], $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.lines.0.counted_qty', '95');
        $this->postJson("/api/v1/inventory/counts/{$count['id']}/submit", [], $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.lines.0.variance_qty', '-3');

        $this->approve($count['id']);

        // The sale during the count is already in the balance: 95 counted
        // against 100 − 2 is a loss of 3, and stock ends at what was counted.
        $this->assertSame('95', $this->onHand($this->soap));
        $this->getJson("/api/v1/inventory/counts/{$count['id']}", $this->headersFor())->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.loss', ['amount_minor' => '300', 'currency' => 'KES'])
            ->assertJsonPath('data.lines.0.variance_qty', '-3');
        Event::assertDispatched(StockCountPosted::class, fn (StockCountPosted $e) => $e->lossMinor === '300' && $e->gainMinor === '0');
    }

    public function test_goods_received_during_a_count_are_not_counted_twice(): void
    {
        $count = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->locationA->id, 'kind' => 'cycle', 'item_ids' => [$this->soap->id]], $this->headersFor())->assertCreated()->json('data');
        // Goods arrive while counting (never refused, flagged); the shelf now holds 110.
        [$receipt] = $this->move($this->soap, Movement::RECEIPT, '10', 1000, manual: false);
        $this->assertContains('during_count', $receipt->flagCodes());

        $this->putJson("/api/v1/inventory/counts/{$count['id']}/lines", ['lines' => [['id' => $count['lines'][0]['id'], 'counted_qty' => '110']]], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/inventory/counts/{$count['id']}/submit", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.lines.0.variance_qty', '0');
        $this->assertSame('110', $this->onHand($this->soap));
    }

    public function test_a_sale_during_a_count_is_not_counted_twice(): void
    {
        $count = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->locationA->id, 'kind' => 'cycle', 'item_ids' => [$this->soap->id]], $this->headersFor())->assertCreated()->json('data');
        $this->move($this->soap, Movement::SALE, '-4', manual: false);

        $this->putJson("/api/v1/inventory/counts/{$count['id']}/lines", ['lines' => [['id' => $count['lines'][0]['id'], 'counted_qty' => '96']]], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/inventory/counts/{$count['id']}/submit", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('96', $this->onHand($this->soap));
    }

    public function test_a_count_without_variance_posts_at_once(): void
    {
        $count = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->locationA->id, 'kind' => 'full'], $this->headersFor())->assertCreated()->json('data');
        $this->assertSame([$this->soap->id], array_column(array_column($count['lines'], 'item'), 'id'));
        $this->putJson("/api/v1/inventory/counts/{$count['id']}/lines", ['lines' => [['id' => $count['lines'][0]['id'], 'counted_qty' => '100']]], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/inventory/counts/{$count['id']}/submit", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame(0, $this->inTenant(fn () => ApprovalRequest::query()->count()));
    }

    public function test_documents_are_refused_without_permission_and_out_of_scope_or_of_another_tenant(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $body = ['location_id' => $this->locationA->id, 'reason' => 'found', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']]];

        $this->postJson('/api/v1/inventory/adjustments', $body, $this->headersFor($cashier))->assertForbidden();
        $this->getJson('/api/v1/inventory/adjustments', $this->headersFor($cashier))->assertForbidden();

        // A storekeeper of A cannot adjust at B (403) nor see A's documents from B.
        $this->postJson('/api/v1/inventory/adjustments', [...$body, 'location_id' => $this->locationB->id], $this->headersFor($this->storekeeper))->assertForbidden();
        $id = $this->postJson('/api/v1/inventory/adjustments', $body, $this->headersFor($this->storekeeper))->assertCreated()->json('data.id');
        $keeperB = $this->userWith('storekeeper', Scope::location($this->locationB->id));
        $this->getJson("/api/v1/inventory/adjustments/{$id}", $this->headersFor($keeperB))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/inventory/adjustments', $this->headersFor($keeperB))->assertOk()->json('data'));

        // Another tenant: its ids do not exist here.
        $other = $this->otherTenant();
        $this->getJson('/api/v1/inventory/adjustments', $this->headersFor($other['user']))->assertForbidden()->assertJsonPath('code', 'module_inactive');
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->getJson("/api/v1/inventory/adjustments/{$id}", $this->headersFor($other['user']))->assertNotFound();
        $this->postJson('/api/v1/inventory/adjustments', $body, $this->headersFor($other['user']))->assertUnprocessable()->assertJsonValidationErrors(['location_id', 'lines.0.item_id']);
    }

    public function test_a_batch_cannot_go_below_zero_when_the_company_does_not_allow_it(): void
    {
        $this->costing(InventorySetting::WEIGHTED_AVERAGE, allowNegative: false);
        $first = $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->milk->id, 'location_id' => $this->locationA->id, 'qty' => '5', 'unit_cost' => '1', 'batch_number' => 'LOT-1', 'expires_on' => '2027-01-31'], $this->headersFor())
            ->assertCreated()->json('data.batch.id');
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->milk->id, 'location_id' => $this->locationA->id, 'qty' => '10', 'unit_cost' => '1', 'batch_number' => 'LOT-2', 'expires_on' => '2027-02-28'], $this->headersFor())->assertCreated();

        // 15 at the outlet, but only 5 of LOT-1.
        try {
            $this->move($this->milk, Movement::ADJUSTMENT_OUT, '-6', extra: ['batch_id' => $first]);
            $this->fail('A batch went below zero.');
        } catch (ApiException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }

        $this->assertSame('15', $this->onHand($this->milk));
    }

    public function test_an_adjustment_approved_during_a_count_waits_and_posts_when_the_count_ends(): void
    {
        $adjustment = $this->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $this->locationA->id, 'reason' => 'damage', 'lines' => [['item_id' => $this->soap->id, 'qty' => '-3']],
        ], $this->headersFor($this->storekeeper))->assertCreated()->json('data.id');
        $count = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->locationA->id, 'kind' => 'cycle', 'item_ids' => [$this->soap->id]], $this->headersFor())->assertCreated()->json('data');

        $this->approve($adjustment);
        $this->getJson("/api/v1/inventory/adjustments/{$adjustment}", $this->headersFor())->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.failure', null);
        $this->assertSame('100', $this->onHand($this->soap));

        $this->postJson("/api/v1/inventory/counts/{$count['id']}/cancel", ['reason' => 'Later'], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/inventory/adjustments/{$adjustment}", $this->headersFor())->assertJsonPath('data.status', 'posted');
        $this->assertSame('97', $this->onHand($this->soap));
    }

    public function test_amounts_in_the_inbox_follow_valuation_where_the_documents_are_seen(): void
    {
        $type = app(TransferType::class);
        $this->assertSame([], $this->inTenant(fn () => $type->hiddenSummaryFields($this->manager)));

        // The manager of A also keeps stock at B, without values there.
        $this->inTenant(fn () => $this->assign($this->manager, $this->roles->get('storekeeper'), Scope::location($this->locationB->id)));
        $this->assertSame(['amount'], $this->inTenant(fn () => $type->hiddenSummaryFields($this->manager->fresh())));
        $this->assertSame(['amount'], $this->inTenant(fn () => $type->hiddenSummaryFields($this->storekeeper)));
    }

    public function test_postings_lock_the_company_before_writing_audited_documents(): void
    {
        $this->inTenant(fn () => Company::query()->whereKey($this->acme->id)->update(['base_currency_locked_at' => null]));
        $adjustment = $this->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $this->locationA->id, 'reason' => 'found', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']],
        ], $this->headersFor($this->storekeeper))->assertCreated()->json('data.id');
        $this->inTenant(fn () => Company::query()->whereKey($this->acme->id)->update(['base_currency_locked_at' => null]));

        $order = [];
        DB::listen(function ($query) use (&$order) {
            if (str_contains($query->sql, 'from "companies"') && str_contains($query->sql, 'for update')) {
                $order[] = 'company';
            } elseif (str_contains($query->sql, 'audit_chain_heads')) {
                $order[] = 'audit';
            }
        });
        $this->inTenant(fn () => app(Adjustments::class)->completed($adjustment, 'approved', $this->manager->id));

        $this->assertSame('company', $order[0] ?? null, implode(',', $order));
    }

    public function test_another_companys_private_item_is_refused_everywhere(): void
    {
        $other = $this->inTenant(function () {
            $company = $this->company('Second co');

            return Item::create(['company_id' => $company->id, 'code' => 'PRIVATE', 'name' => 'Private item', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
        });
        $headers = $this->headersFor();
        $at = $this->locationA->id;

        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $other->id, 'location_id' => $at, 'qty' => '1', 'unit_cost' => '1'], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'item_not_at_location');
        $this->postJson('/api/v1/inventory/adjustments', ['location_id' => $at, 'reason' => 'found', 'lines' => [['item_id' => $other->id, 'qty' => '1']]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'item_not_at_location');
        $this->postJson('/api/v1/inventory/transfers', ['from_location_id' => $at, 'to_location_id' => $this->locationB->id, 'lines' => [['item_id' => $other->id, 'qty' => '1']]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'item_not_at_location');
        $this->putJson('/api/v1/inventory/reorder-rules', ['item_id' => $other->id, 'location_id' => $at, 'reorder_level' => '1'], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'item_not_at_location');
        $this->postJson('/api/v1/inventory/counts', ['location_id' => $at, 'kind' => 'cycle', 'item_ids' => [$other->id]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'item_not_at_location');

        $list = $this->inTenant(fn () => PriceList::create(['company_id' => $this->acme->id, 'name' => 'Retail', 'currency' => 'KES', 'tax_inclusive' => true, 'is_default' => true]));
        $this->postJson('/api/v1/inventory/labels', ['labels' => [['item_id' => $other->id, 'copies' => 1]], 'price_list_id' => $list->id], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'item_not_at_location');
    }

    public function test_the_module_switched_off_closes_the_routes(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));

        $this->getJson('/api/v1/inventory/stock', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
