<?php

namespace Modules\Stores\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Stores\Events\StoreIssued;
use Modules\Stores\Events\StoreReturned;
use Modules\Stores\Models\Movement;
use Modules\Stores\Models\RequisitionLine;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// STR-04 to STR-07, STR-10, STR-11, STR-15 with only the core (Inventory
// off): full and partial issues, the department's acknowledgement, returns,
// and the store's own append-only ledger.
class IssuesAndReturnsTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpStores();
        $this->opening($this->soap, '30');
        $this->opening($this->sugar, '10.5');
    }

    public function test_partial_issues_follow_the_requisition_until_it_is_fully_issued(): void
    {
        Event::fake([StoreIssued::class]);
        $requisition = $this->approved([
            ['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '2'],
            ['item_id' => $this->sugar->id, 'qty' => '4'],
        ]);
        [$soapLine, $sugarLine] = array_column($requisition['lines'], 'id');

        $first = $this->issue($requisition['id'], [[$soapLine, '1'], [$sugarLine, '2.5']])->assertCreated()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.ledger', 'stores')
            ->assertJsonPath('data.lines.0.qty_base', '12')
            ->assertJsonPath('data.value', null)
            ->json('data');
        $this->assertMatchesRegularExpression('/^ISS-A-\d{4}-00001$/', $first['number']);
        $this->assertSame(['18', '8'], [$this->onHand($this->soap), $this->onHand($this->sugar)]);
        $this->getJson("/api/v1/stores/requisitions/{$requisition['id']}", $this->headersFor())
            ->assertJsonPath('data.status', 'partially_issued')
            ->assertJsonPath('data.lines.0.qty_issued', '1')
            ->assertJsonPath('data.lines.1.qty_open', '1.5');

        // Never more than approved; the error names the open quantity.
        $this->issue($requisition['id'], [[$soapLine, '2']])->assertUnprocessable()->assertJsonPath('code', 'over_issue')->assertJsonValidationErrors('lines.0.qty');
        $this->issue($requisition['id'], [[$soapLine, '1'], [$sugarLine, '1.5']])->assertCreated();
        $this->getJson("/api/v1/stores/requisitions/{$requisition['id']}", $this->headersFor())->assertJsonPath('data.status', 'issued')->assertJsonCount(2, 'data.issues');
        $this->issue($requisition['id'], [[$sugarLine, '0.5']])->assertStatus(409);
        $this->assertSame(['6', '6.5'], [$this->onHand($this->soap), $this->onHand($this->sugar)]);

        Event::assertDispatchedTimes(StoreIssued::class, 2);
        Event::assertDispatched(StoreIssued::class, fn (StoreIssued $e) => $e->issueId === $first['id'] && $e->ledger === 'stores'
            && $e->departmentId === $this->kitchen->id && $e->lines[0]['qty_base'] === '12' && $e->lines[1]['qty'] === '2.5');
    }

    public function test_the_own_ledger_refuses_what_the_store_does_not_hold_and_keeps_its_balance(): void
    {
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '40']]);
        $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '31']])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame('30', $this->onHand($this->soap));
        $this->getJson("/api/v1/stores/requisitions/{$requisition['id']}", $this->headersFor())->assertJsonPath('data.lines.0.qty_issued', '0');

        $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '30']])->assertCreated();
        $ledger = $this->getJson('/api/v1/stores/ledger?item='.$this->soap->id.'&sort=occurred_at', $this->headersFor())->assertOk()->assertJsonPath('meta.ledger', 'stores')->json('data');
        $this->assertSame([['opening', '30', '30'], ['issue', '-30', '0']], array_map(fn ($m) => [$m['kind'], $m['qty'], $m['balance_after']], $ledger));
        $this->assertSame('Kitchen', $ledger[1]['department']['name']);

        // STR-07: the ledger is append-only, for the owner role too.
        $this->expectException(QueryException::class);
        $this->inTenant(fn () => DB::table('stores_movements')->where('kind', 'issue')->update(['qty' => -1]));
    }

    public function test_the_department_acknowledges_but_never_the_issuer(): void
    {
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => '3']]);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '3']])->assertCreated()->json('data');
        $this->assertFalse($issue['can']['acknowledge']);

        $this->postJson("/api/v1/stores/issues/{$issue['id']}/acknowledge", [], $this->headersFor($this->storekeeper))->assertForbidden();
        $this->assertCount(1, $this->getJson('/api/v1/stores/issues?status=issued', $this->headersFor())->json('data'));

        $chef = $this->userWith('branch_manager', Scope::location($this->store->id));
        $this->getJson("/api/v1/stores/issues/{$issue['id']}", $this->headersFor($chef))->assertJsonPath('data.can.acknowledge', true);
        $this->postJson("/api/v1/stores/issues/{$issue['id']}/acknowledge", ['note' => 'All there'], $this->headersFor($chef))
            ->assertOk()->assertJsonPath('data.status', 'acknowledged')->assertJsonPath('data.acknowledged_by.id', $chef->id)->assertJsonPath('data.acknowledge_note', 'All there');
        $this->postJson("/api/v1/stores/issues/{$issue['id']}/acknowledge", [], $this->headersFor($chef))->assertStatus(409);
        $this->assertCount(0, $this->getJson('/api/v1/stores/issues?status=issued', $this->headersFor())->json('data'));

        // The issuer, even with the permission, is refused (STR-05).
        $second = $this->approved([['item_id' => $this->soap->id, 'qty' => '1']]);
        $own = $this->issue($second['id'], [[$second['lines'][0]['id'], '1']], $chef)->assertCreated()->json('data');
        $this->postJson("/api/v1/stores/issues/{$own['id']}/acknowledge", [], $this->headersFor($chef))->assertForbidden()->assertJsonPath('code', 'acknowledge_self');
    }

    public function test_returns_come_back_against_the_issue_up_to_what_was_issued(): void
    {
        Event::fake([StoreReturned::class]);
        $requisition = $this->approved([['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '2'], ['item_id' => $this->sugar->id, 'qty' => '3']]);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '2'], [$requisition['lines'][1]['id'], '3']])->assertCreated()->json('data');
        [$soapLine, $sugarLine] = array_column($issue['lines'], 'id');
        $this->assertSame(['6', '7.5'], [$this->onHand($this->soap), $this->onHand($this->sugar)]);

        $return = $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", [
            'reason' => 'unused', 'note' => 'Box unopened', 'lines' => [['issue_line_id' => $soapLine, 'qty' => '1'], ['issue_line_id' => $sugarLine, 'qty' => '0.75']],
        ], $this->headersFor($this->storekeeper))->assertCreated()
            ->assertJsonPath('data.reason', 'unused')
            ->assertJsonPath('data.lines.0.qty_base', '12')
            ->assertJsonPath('data.lines.1.qty', '0.75')
            ->json('data');
        $this->assertMatchesRegularExpression('/^SRN-A-\d{4}-00001$/', $return['number']);
        $this->assertSame(['18', '8.25'], [$this->onHand($this->soap), $this->onHand($this->sugar)]);

        $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['reason' => 'excess', 'lines' => [['issue_line_id' => $soapLine, 'qty' => '2']]], $this->headersFor($this->storekeeper))
            ->assertUnprocessable()->assertJsonPath('code', 'over_return');
        $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['reason' => 'excess', 'lines' => [['issue_line_id' => $soapLine, 'qty' => '1']]], $this->headersFor($this->storekeeper))->assertCreated();
        $this->getJson("/api/v1/stores/issues/{$issue['id']}", $this->headersFor())
            ->assertJsonPath('data.lines.0.qty_returned_base', '24')
            ->assertJsonCount(2, 'data.returns');
        $this->assertSame('30', $this->onHand($this->soap));
        $this->assertSame(['SRN'], array_unique(array_map(fn ($r) => substr($r['number'], 0, 3), $this->getJson('/api/v1/stores/returns', $this->headersFor())->assertOk()->json('data'))));

        // A line of another issue is refused; the reason is required.
        $other = $this->issue($this->approved([['item_id' => $this->soap->id, 'qty' => '1']])['id'], [[$this->inTenant(fn () => RequisitionLine::query()->latest('created_at')->value('id')), '1']])->assertCreated()->json('data');
        $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $other['lines'][0]['id'], 'qty' => '1']]], $this->headersFor())->assertUnprocessable();
        $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['lines' => [['issue_line_id' => $sugarLine, 'qty' => '1']]], $this->headersFor())->assertUnprocessable();

        Event::assertDispatched(StoreReturned::class, fn (StoreReturned $e) => $e->returnId === $return['id'] && $e->ledger === 'stores' && $e->lines[0]['issue_line_id'] === $soapLine);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'stores.return.create')->exists()));
    }

    public function test_stock_entries_and_goods_received_fill_the_own_ledger(): void
    {
        $this->postJson('/api/v1/stores/stock-entries', [
            'location_id' => $this->store->id, 'kind' => 'adjustment', 'reason' => 'count',
            'lines' => [['item_id' => $this->soap->id, 'qty' => '-5'], ['item_id' => $this->sugar->id, 'qty' => '0.5']],
        ], $this->headersFor($this->storekeeper))->assertCreated()->assertJsonPath('data.0.kind', 'adjustment_out')->assertJsonPath('data.1.kind', 'adjustment_in');
        $this->assertSame(['25', '11'], [$this->onHand($this->soap), $this->onHand($this->sugar)]);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'stores.stock.adjust')->exists()));

        // Never below zero; an adjustment needs a reason; only at a store.
        $this->postJson('/api/v1/stores/stock-entries', ['location_id' => $this->store->id, 'kind' => 'adjustment', 'reason' => 'damage', 'lines' => [['item_id' => $this->soap->id, 'qty' => '-26']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->postJson('/api/v1/stores/stock-entries', ['location_id' => $this->store->id, 'kind' => 'adjustment', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']]], $this->headersFor())->assertUnprocessable();
        $this->postJson('/api/v1/stores/stock-entries', ['location_id' => $this->locationA->id, 'kind' => 'opening', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'not_a_store');
        $this->postJson('/api/v1/stores/stock-entries', ['location_id' => $this->store->id, 'kind' => 'opening', 'lines' => [['item_id' => $this->soap->id, 'qty' => '-1']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'qty_invalid');

        // STR-07: goods received at the store (Procurement's GRN) while Inventory is off, once.
        $receipt = (string) Str::uuid7();
        $event = [$this->owner->tenant_id, $receipt, $this->acme->id, $this->store->id, now()->toIso8601String(), 'GRN-0001',
            [['line_id' => (string) Str::uuid7(), 'item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '1', 'cost_minor' => '1200', 'currency' => 'KES']]];
        $this->inTenant(fn () => GoodsReceived::dispatch(...$event));
        $this->inTenant(fn () => GoodsReceived::dispatch(...$event));
        $this->assertSame('37', $this->onHand($this->soap));
        $this->assertSame(1, $this->movements(Movement::SOURCE_GOODS_RECEIPT));

        $stock = $this->getJson('/api/v1/stores/stock', $this->headersFor())->assertOk()->json('data');
        $this->assertSame([['Soap', '37'], ['Sugar', '11']], array_map(fn ($row) => [$row['item']['name'], $row['qty']], $stock));
        $this->assertStringContainsString('Sugar', $this->get('/api/v1/stores/stock?format=csv', $this->headersFor())->assertOk()->streamedContent());
        $this->assertStringContainsString('GRN-0001', $this->get('/api/v1/stores/ledger?format=csv', $this->headersFor())->assertOk()->streamedContent());
    }
}
