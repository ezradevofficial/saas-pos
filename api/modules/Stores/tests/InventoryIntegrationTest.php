<?php

namespace Modules\Stores\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Listeners\MoveStockForStores;
use Modules\Inventory\Listeners\MoveStoreStock;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Movement as InventoryMovement;
use Modules\Stores\Events\StoreConsumptionValued;
use Modules\Stores\Events\StoreIssued;
use Modules\Stores\Events\StoreReturned;
use Modules\Stores\Listeners\RecordStoreValues;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\Movement;
use Modules\Stores\Stock\Qty;
use Modules\Stores\Stock\StoreLedger;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// STR-08, STR-09, STR-16 with Inventory active: issues and returns move
// stock through Inventory's ledger exactly once (redelivered events move
// nothing), at Inventory's cost; Stores records the values, raises
// StoreConsumptionValued once, keeps no balance of its own and refuses
// its own stock entries; a return follows its issue's ledger.
class InventoryIntegrationTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    /** @var list<StoreConsumptionValued> */
    private array $valued = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpStores(inventory: true);
        Event::listen(StoreConsumptionValued::class, fn (StoreConsumptionValued $e) => $this->valued[] = $e);

        // FIFO: 10 soap at KES 1.00, then 10 at KES 2.00 (minor units).
        $this->putJson("/api/v1/inventory/companies/{$this->acme->id}/settings", ['costing_method' => 'fifo', 'allow_negative' => true], $this->headersFor())->assertOk();
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->soap->id, 'location_id' => $this->store->id, 'qty' => '10', 'unit_cost' => '1'], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->soap->id, 'location_id' => $this->store->id, 'qty' => '10', 'unit_cost' => '2'], $this->headersFor())->assertCreated();
    }

    private function inventoryOnHand(): string
    {
        return $this->inTenant(fn () => Qty::show((string) (Balance::query()->where('item_id', $this->soap->id)->where('location_id', $this->store->id)->value('qty') ?? '0')));
    }

    private function inventoryMovements(string $sourceType): int
    {
        return $this->inTenant(fn () => InventoryMovement::query()->where('source_type', $sourceType)->count());
    }

    public function test_an_issue_moves_inventory_stock_once_at_its_cost_and_the_value_comes_back(): void
    {
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '15']], null);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '15']])->assertCreated()
            ->assertJsonPath('data.ledger', 'inventory')->json('data');

        $this->assertSame('5', $this->inventoryOnHand());
        $this->assertSame(1, $this->inventoryMovements('stores.issue'));
        // FIFO: 10 × 100 + 5 × 200.
        $this->getJson("/api/v1/stores/issues/{$issue['id']}", $this->headersFor())
            ->assertJsonPath('data.valued', true)
            ->assertJsonPath('data.value', ['amount_minor' => '2000', 'currency' => 'KES'])
            ->assertJsonPath('data.lines.0.value', ['amount_minor' => '2000', 'currency' => 'KES']);
        // Stores' own rows record the issue, with Inventory's value and no balance of their own.
        $row = $this->inTenant(fn () => Movement::query()->where('source_id', $issue['id'])->sole());
        $this->assertSame(['inventory', null, '-2000', 'KES'], [$row->ledger, $row->balance_after, (string) $row->value_minor, $row->currency]);
        // STR-14, RBAC-05: the issue voucher prints the value for value holders only.
        $this->assertStringContainsString('20.00', $this->get("/api/v1/stores/issues/{$issue['id']}/print?document=issue_voucher", $this->headersFor())->assertOk()->getContent());
        $this->assertStringNotContainsString('20.00', $this->get("/api/v1/stores/issues/{$issue['id']}/print?document=issue_voucher", $this->headersFor($this->storekeeper))->assertOk()->getContent());
        $this->assertCount(1, $this->valued);
        $this->assertSame(['issue', '2000', $this->kitchen->id], [$this->valued[0]->direction, $this->valued[0]->valueMinor, $this->valued[0]->departmentId]);

        // Delivered again (a retried queue job): nothing moves twice, nothing is valued twice.
        $event = $this->inTenant(fn () => Issue::query()->findOrFail($issue['id']));
        $this->inTenant(fn () => StoreIssued::dispatch($this->owner->tenant_id, $event->id, $event->number, $event->company_id, $event->branch_id, $event->location_id,
            $event->department_id, $event->cost_centre_id, 'inventory', $event->issued_at->toIso8601String(), null,
            [['line_id' => $issue['lines'][0]['id'], 'item_id' => $this->soap->id, 'uom_id' => $this->each->id, 'qty' => '15', 'qty_base' => '15']]));
        $this->assertSame(['5', 1], [$this->inventoryOnHand(), $this->inventoryMovements('stores.issue')]);
        $this->assertCount(1, $this->valued);
    }

    public function test_a_return_comes_back_at_the_cost_its_issue_went_out_at_once(): void
    {
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '15']]);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '15']])->assertCreated()->json('data');

        $return = $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $issue['lines'][0]['id'], 'qty' => '5']]], $this->headersFor($this->storekeeper))
            ->assertCreated()->json('data');
        $this->assertSame('10', $this->inventoryOnHand());
        // 5 of the 15 that left (worth 2000): a third of the value.
        $this->getJson("/api/v1/stores/returns/{$return['id']}", $this->headersFor())->assertJsonPath('data.value', ['amount_minor' => '667', 'currency' => 'KES']);
        $this->assertSame(['issue', 'return'], array_map(fn ($e) => $e->direction, $this->valued));

        $this->inTenant(fn () => StoreReturned::dispatch($this->owner->tenant_id, $return['id'], $return['number'], $issue['id'], $this->acme->id, $this->branchA->id, $this->store->id,
            $this->kitchen->id, null, 'inventory', now()->toIso8601String(), null,
            [['line_id' => $return['lines'][0]['id'], 'issue_line_id' => $issue['lines'][0]['id'], 'item_id' => $this->soap->id, 'uom_id' => $this->each->id, 'qty' => '5', 'qty_base' => '5']]));
        $this->assertSame(['10', 1], [$this->inventoryOnHand(), $this->inventoryMovements('stores.return')]);

        // The rest comes back with exactly what is left of the value.
        $rest = $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['reason' => 'excess', 'lines' => [['issue_line_id' => $issue['lines'][0]['id'], 'qty' => '10']]], $this->headersFor($this->storekeeper))
            ->assertCreated()->assertJsonPath('data.valued', true)->assertJsonPath('data.value', null)->json('data');
        // RBAC-05: storekeepers see quantities; value holders see the value.
        $this->getJson("/api/v1/stores/returns/{$rest['id']}", $this->headersFor())->assertJsonPath('data.value.amount_minor', '1333');
        $this->assertSame('20', $this->inventoryOnHand());
    }

    public function test_inventory_issues_are_never_refused_and_own_entries_are(): void
    {
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '25']]);
        $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '25']])->assertCreated();
        $this->assertSame('-5', $this->inventoryOnHand());
        $flags = $this->inTenant(fn () => InventoryMovement::query()->where('source_type', 'stores.issue')->sole()->flags);
        $this->assertContains(['code' => 'negative_stock'], $flags);

        $this->postJson('/api/v1/stores/stock-entries', ['location_id' => $this->store->id, 'kind' => 'opening', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']]], $this->headersFor())
            ->assertStatus(409)->assertJsonPath('code', 'inventory_active');
        $this->getJson('/api/v1/stores/status', $this->headersFor())->assertOk()->assertJsonPath('data.inventory_active', true);
        $this->assertSame([], $this->getJson('/api/v1/stores/stock', $this->headersFor())->assertOk()->json('data'));
    }

    public function test_a_return_follows_its_issues_ledger_in_both_directions(): void
    {
        // An own-ledger issue (Inventory off), returned once Inventory is on: back into the own ledger.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));
        $this->opening($this->soap, '5');
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '2']]);
        $own = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '2']])->assertCreated()->assertJsonPath('data.ledger', 'stores')->json('data');
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->getJson("/api/v1/stores/issues/{$own['id']}", $this->headersFor())->assertJsonPath('data.can.return', true);
        $this->postJson("/api/v1/stores/issues/{$own['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $own['lines'][0]['id'], 'qty' => '1']]], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.ledger', 'stores');
        $this->assertSame(['4', 0, '20'], [$this->onHand($this->soap), $this->inventoryMovements('stores.return'), $this->inventoryOnHand()]);

        // An Inventory-ledger issue, returned once Inventory is off: back through Inventory, at its cost.
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '6']]);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '6']])->assertCreated()->assertJsonPath('data.ledger', 'inventory')->json('data');
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));
        $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $issue['lines'][0]['id'], 'qty' => '2']]], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.ledger', 'inventory')->assertJsonPath('data.valued', true);
        $this->assertSame(['16', 1], [$this->inventoryOnHand(), $this->inventoryMovements('stores.return')]);
    }

    public function test_an_inventory_ledger_issue_posts_even_when_inventory_was_switched_off_before_its_job_ran(): void
    {
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '4']]);
        Queue::fake([MoveStoreStock::class]);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '4']])->assertCreated()->assertJsonPath('data.ledger', 'inventory')->json('data');
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));

        $job = Queue::pushed(MoveStoreStock::class)->sole();
        $this->inTenant(fn () => $job->handle(app(MoveStockForStores::class)));

        $this->assertSame(['16', 1], [$this->inventoryOnHand(), $this->inventoryMovements('stores.issue')]);
        $this->getJson("/api/v1/stores/issues/{$issue['id']}", $this->headersFor())->assertJsonPath('data.value.amount_minor', '400');
    }

    public function test_values_missed_once_are_recorded_by_the_retry_or_a_redelivered_posting(): void
    {
        $listener = new \ReflectionClass(RecordStoreValues::class);
        $this->assertTrue($listener->implementsInterface(ShouldQueue::class));
        $this->assertTrue((new RecordStoreValues(app(TenantContext::class), app(StoreLedger::class)))->afterCommit);

        // The queued listener fails once (its first attempt), then the retry values the document.
        $queue = Queue::getFacadeRoot();
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '3']]);
        $later = $this->approved([['item_id' => $this->soap->id, 'qty' => '2']]);
        Queue::fake([CallQueuedListener::class]);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '3']])->assertCreated()->json('data');
        $queued = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === RecordStoreValues::class)->sole();
        $failing = $this->createMock(StoreLedger::class);
        $failing->method('value')->willThrowException(new \RuntimeException('database went away'));
        try {
            (new RecordStoreValues(app(TenantContext::class), $failing))->handle($queued->data[0]);
            $this->fail('the first attempt should fail');
        } catch (\RuntimeException) {
        }
        $this->getJson("/api/v1/stores/issues/{$issue['id']}", $this->headersFor())->assertJsonPath('data.valued', false);
        app(RecordStoreValues::class)->handle($queued->data[0]);
        $this->getJson("/api/v1/stores/issues/{$issue['id']}", $this->headersFor())->assertJsonPath('data.valued', true)->assertJsonPath('data.value.amount_minor', '300');
        $this->assertCount(1, $this->valued);
        $this->assertSame('0', $this->valued[0]->lines[0]['cost_variance_minor']);

        // Lost altogether (the queued listener never ran): Inventory's job delivered again raises StockMoved again.
        $lost = $this->issue($later['id'], [[$later['lines'][0]['id'], '2']])->assertCreated()->json('data');
        Queue::swap($queue);
        $this->getJson("/api/v1/stores/issues/{$lost['id']}", $this->headersFor())->assertJsonPath('data.valued', false);
        $event = $this->inTenant(fn () => Issue::query()->findOrFail($lost['id']));
        $this->inTenant(fn () => StoreIssued::dispatch($this->owner->tenant_id, $event->id, $event->number, $event->company_id, $event->branch_id, $event->location_id,
            $event->department_id, $event->cost_centre_id, 'inventory', $event->issued_at->toIso8601String(), null,
            [['line_id' => $lost['lines'][0]['id'], 'item_id' => $this->soap->id, 'uom_id' => $this->each->id, 'qty' => '2', 'qty_base' => '2']]));
        $this->getJson("/api/v1/stores/issues/{$lost['id']}", $this->headersFor())->assertJsonPath('data.valued', true)->assertJsonPath('data.value.amount_minor', '200');
        $this->assertSame(2, $this->inventoryMovements('stores.issue'));
        $this->assertCount(2, $this->valued);
    }
}
