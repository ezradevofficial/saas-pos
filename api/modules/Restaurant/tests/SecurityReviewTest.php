<?php

namespace Modules\Restaurant\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\Models\LimitRule;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Models\TicketLine;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The security review of the Restaurant module: a void needs real approval,
// limits are in the base currency, merges respect permissions and payments,
// modifiers are priced by the server, and one tenant's ids never break
// another's upload.
class SecurityReviewTest extends TestCase
{
    use BuildsExchangeRates, BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    /** @return array{0: array, 1: array} a table with one sent line, and that line */
    private function table(array $record = [], array $line = []): array
    {
        $sent = $this->sent($this->orderLine($line));
        $record = $this->orderRecord([$sent], $record);
        $this->store($record);

        return [$record, $sent];
    }

    private function remove(array $record, array $line, array $removed): array
    {
        $record['lines'] = [array_replace($line, ['removed' => $removed])];

        return $this->sync([$record])->json('results.0.lines.0');
    }

    // ---- 1. a void needs real approval ----

    public function test_an_override_that_names_no_readable_manager_is_refused(): void
    {
        [$record, $sent] = $this->table();

        foreach ([['token' => 'junk'], ['signature' => 'forged'], ['manager_user_id' => $this->id(), 'signature' => 'valid']] as $override) {
            $result = $this->remove($record, $sent, $this->removed($this->waiter, extra: ['override' => $override]));

            $this->assertSame('rejected', $result['status'], json_encode($override));
            $this->assertContains($result['error']['code'], ['override_required', 'user_unknown']);
        }

        $this->assertNull(OrderLine::query()->find($sent['id'])->voided_at);
        $this->assertSame(0, Ticket::query()->where('kind', 'void')->count());
    }

    public function test_a_named_manager_the_server_cannot_prove_only_asks_and_a_reviewer_decides(): void
    {
        [$record, $sent] = $this->table();

        $result = $this->remove($record, $sent, $this->removed($this->waiter, extra: ['override' => ['manager_user_id' => $this->manager->id, 'signature' => 'unverifiable']]));

        $this->assertSame(['stored', 'pending'], [$result['status'], $result['void']]);
        $line = OrderLine::query()->find($sent['id']);
        $this->assertNull($line->voided_at);
        $this->assertSame($this->manager->id, $line->pending_void['approver_id']);
        $this->assertContains('override_unverified', array_column($line->flags, 'code'));
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.order.void_requested')->count());

        // The same record again asks nothing twice.
        $this->remove($record, $sent, $this->removed($this->waiter, extra: ['override' => ['manager_user_id' => $this->manager->id, 'signature' => 'unverifiable']]));
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.order.void_requested')->count());

        // A reviewer who may void approves it with the void route; it then applies and tells the station.
        $this->api('POST', "restaurant/orders/{$record['id']}/lines/{$sent['id']}/void", ['reason' => 'Guest left'], $this->manager)->assertOk()->assertJsonPath('data.lines.0.void.after_send', true)->assertJsonPath('data.lines.0.void_pending', null);
        $this->assertSame(1, Ticket::query()->where('kind', 'void')->count());
    }

    public function test_a_reviewer_can_turn_a_pending_void_down(): void
    {
        [$record, $sent] = $this->table();
        $this->remove($record, $sent, $this->removed($this->manager, extra: ['actor_proof' => null]));
        $url = "restaurant/orders/{$record['id']}/lines/{$sent['id']}/void/reject";

        $this->api('POST', $url, [], $this->waiter)->assertForbidden();
        $this->api('POST', $url, [], $this->manager)->assertOk()->assertJsonPath('data.lines.0.void_pending', null);
        $this->assertNull(OrderLine::query()->find($sent['id'])->voided_at);
        $this->api('POST', $url, [], $this->manager)->assertConflict()->assertJsonPath('code', 'no_pending_void');
    }

    // ---- 2. the void limit is in the base currency ----

    public function test_the_void_limit_is_compared_in_the_base_currency_at_the_current_rate(): void
    {
        // A USD 50.00 dish (USD 56.25 with tax) at 1 USD = 130 KES is KES 7,312.50, above a limit of KES 5,000 although 56.25 is under it.
        $this->rate('USD', 'KES', '130');
        $this->inTenant(fn () => LimitRule::where('role_id', $this->roles->get('branch_manager')->id)->where('key', 'max_refund_amount')->update(['value' => '5000']));
        [$record, $sent] = $this->table(['currency' => 'USD'], ['unit_price_minor' => '5000']);
        $proof = ['actor_proof' => $this->actorProof($this->manager->id, online: true)];

        $result = $this->remove($record, $sent, $this->removed($this->manager, extra: $proof));
        $this->assertSame('void_limit_exceeded', $result['error']['code']);

        $this->inTenant(fn () => LimitRule::where('key', 'max_refund_amount')->update(['value' => '8000']));
        $this->assertSame('stored', $this->remove($record, $sent, $this->removed($this->manager, extra: $proof))['status']);
    }

    public function test_the_back_office_void_uses_the_same_limit(): void
    {
        $this->rate('USD', 'KES', '130');
        $this->inTenant(fn () => LimitRule::where('role_id', $this->roles->get('branch_manager')->id)->where('key', 'max_refund_amount')->update(['value' => '5000']));
        [$record, $sent] = $this->table(['currency' => 'USD'], ['unit_price_minor' => '5000']);

        $this->api('POST', "restaurant/orders/{$record['id']}/lines/{$sent['id']}/void", ['reason' => 'Guest left'], $this->manager)->assertForbidden()->assertJsonPath('code', 'void_limit_exceeded');
        $this->api('POST', "restaurant/orders/{$record['id']}/lines/{$sent['id']}/void", ['reason' => 'Guest left'])->assertOk();
    }

    public function test_without_a_rate_a_persons_own_right_is_not_enough_and_a_manager_must_approve(): void
    {
        // No USD to KES rate exists.
        [$record, $sent] = $this->table(['currency' => 'USD'], ['unit_price_minor' => '5000']);
        $proof = ['actor_proof' => $this->actorProof($this->manager->id, online: true)];

        $own = $this->remove($record, $sent, $this->removed($this->manager, extra: $proof));
        $this->assertSame('void_limit_exceeded', $own['error']['code']);

        $this->api('POST', "restaurant/orders/{$record['id']}/lines/{$sent['id']}/void", ['reason' => 'Guest left'], $this->manager)->assertForbidden()->assertJsonPath('code', 'void_limit_exceeded');

        $approved = $this->remove($record, $sent, $this->removed($this->waiter, extra: ['override' => $this->override($this->manager->id)]));
        $this->assertSame('stored', $approved['status']);
        $this->assertNotNull(OrderLine::query()->find($sent['id'])->voided_at);
    }

    public function test_an_order_in_a_currency_the_business_does_not_use_is_refused(): void
    {
        $this->sync([$this->orderRecord([$this->orderLine()], ['currency' => 'XXX'])])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'currency_unknown');
        $this->assertSame(0, Order::query()->count());
    }

    // ---- 5, 6. merge ----

    public function test_a_merge_recorded_by_someone_without_the_right_is_kept_flagged_and_audited(): void
    {
        $a = $this->orderRecord([$this->sent($this->orderLine())]);
        $b = $this->orderRecord([$this->sent($this->orderLine(['name' => 'B1']))], ['table_id' => $this->t2->id]);
        $this->store($a);
        $this->store($b);

        $b['merged_into_id'] = $a['id'];
        $b['updated_by'] = $this->cook->id;
        $b['updated_at'] = now()->toIso8601String();
        $result = $this->store($b);

        $this->assertSame('merged', $result['order']['status']);
        $this->assertSame([['code' => 'merge_unauthorised']], $result['flags']);
        $entry = AuditEntry::query()->where('action', 'restaurant.order.merge')->sole();
        $this->assertSame($this->cook->id, $entry->user_id);
    }

    public function test_a_merge_recorded_by_a_manager_is_not_flagged(): void
    {
        $a = $this->orderRecord([$this->sent($this->orderLine())]);
        $b = $this->orderRecord([$this->sent($this->orderLine(['name' => 'B1']))], ['table_id' => $this->t2->id]);
        $this->store($a);
        $this->store($b);

        $b['merged_into_id'] = $a['id'];
        $b['updated_by'] = $this->manager->id;
        $b['updated_at'] = now()->toIso8601String();

        $this->assertSame([], $this->store($b)['flags']);
    }

    public function test_an_order_with_money_taken_is_not_merged(): void
    {
        $a = $this->orderRecord([$this->sent($this->orderLine())]);
        $b = $this->orderRecord([$this->sent($this->orderLine(['name' => 'B1']))], ['table_id' => $this->t2->id]);
        $this->store($a);
        $this->store($b);
        $this->inTenant(fn () => Settlement::create(['order_id' => $b['id'], 'location_id' => $this->locationA->id, 'sale_id' => $this->id(), 'mode' => 'item', 'final' => false, 'currency' => 'KES', 'total_minor' => '50000', 'line_ids' => [], 'settled_at' => now()]));

        $this->api('POST', "restaurant/orders/{$b['id']}/merge", ['into_order_id' => $a['id']])->assertConflict()->assertJsonPath('code', 'order_has_payments');

        $b['merged_into_id'] = $a['id'];
        $b['updated_at'] = now()->toIso8601String();
        $result = $this->store($b);
        $this->assertSame('open', $result['order']['status']);
        $this->assertSame([['code' => 'merge_refused']], $result['flags']);
        $this->assertSame(1, OrderLine::query()->where('order_id', $b['id'])->count(), 'its lines stay with it');
    }

    // ---- 7. the smaller findings ----

    public function test_removing_an_unsent_line_is_free_but_audited(): void
    {
        $line = $this->orderLine();
        $record = $this->orderRecord([$line]);
        $this->store($record);

        $this->assertSame('stored', $this->remove($record, $line, $this->removed($this->waiter, 'Changed mind'))['status']);

        $entry = AuditEntry::query()->where('action', 'restaurant.order.line_removed')->sole();
        $this->assertSame([$line['id'], 'Changed mind'], [$entry->after['line_id'], $entry->after['reason']]);
    }

    public function test_a_line_the_server_knows_was_sent_is_not_free_whatever_the_till_says(): void
    {
        $line = $this->orderLine();
        $record = $this->orderRecord([$line]);
        $this->store($record);
        // On a ticket, though its `sent_at` was never set: the server's own record says it was sent.
        $this->inTenant(function () use ($line) {
            $ticket = Ticket::create(['order_id' => Order::query()->sole()->id, 'location_id' => $this->locationA->id, 'business_date' => now()->toDateString(), 'number' => 1, 'kind' => 'order', 'status' => 'new']);
            TicketLine::create(['ticket_id' => $ticket->id, 'order_line_id' => $line['id'], 'name' => 'Burger', 'qty' => '1']);
        });

        $this->api('POST', "restaurant/orders/{$record['id']}/lines/{$line['id']}/void", ['reason' => 'Wrong dish'], $this->waiter)->assertForbidden()->assertJsonPath('code', 'void_not_permitted');
        $this->assertSame('override_required', $this->remove($record, $line, $this->removed($this->waiter))['error']['code']);
    }

    public function test_modifiers_are_the_servers_with_its_price(): void
    {
        [$cheese, $price] = $this->modifier('Extra cheese', '5000');
        $line = $this->orderLine(['modifiers' => [['id' => $cheese, 'name' => 'Free cheese', 'price_minor' => '0']]]);

        $stored = $this->store($this->orderRecord([$line]))['order']['lines'][0]['modifiers'];

        $this->assertSame([['id' => $cheese, 'name' => 'Extra cheese', 'price_minor' => $price]], $stored, 'the till cannot name or price a modifier');
    }

    public function test_an_unknown_or_foreign_currency_modifier_refuses_the_line(): void
    {
        [$usd] = $this->modifier('Dollar shot', '100', 'USD');
        $unknown = $this->orderLine(['modifiers' => [['id' => $this->id(), 'name' => 'Invented', 'price_minor' => '0']]]);
        $named = $this->orderLine(['modifiers' => [['id' => 'extra', 'name' => 'Not an id', 'price_minor' => '0']]]);
        $foreign = $this->orderLine(['modifiers' => [['id' => $usd, 'name' => 'Dollar shot', 'price_minor' => '100']]]);

        $result = $this->store($this->orderRecord([$unknown, $named, $foreign]));

        $this->assertSame(['modifier_unknown', 'modifier_unknown', 'modifier_currency'], array_column(array_column($result['lines'], 'error'), 'code'));
        $this->assertSame(0, OrderLine::query()->count());
    }

    public function test_an_id_held_by_another_tenant_refuses_that_record_not_the_batch(): void
    {
        $other = $this->otherTenant();
        $foreignOrder = $this->id();
        $foreignLine = $this->id();

        $this->asTenant($other['user']->tenant_id, function () use ($other, $foreignOrder, $foreignLine) {
            $uom = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $item = Item::create(['code' => 'X', 'name' => 'X', 'type' => 'non_stock', 'base_uom_id' => $uom->id]);
            $order = Order::create([
                'id' => $foreignOrder, 'company_id' => $other['company']->id, 'branch_id' => $other['branch']->id, 'location_id' => $other['location']->id, 'kind' => 'tab', 'tab_name' => 'T',
                'waiter_id' => $other['user']->id, 'currency' => 'KES', 'opened_at' => now(), 'header_at' => now(),
            ]);
            OrderLine::create(['id' => $foreignLine, 'order_id' => $order->id, 'location_id' => $other['location']->id, 'item_id' => $item->id, 'name' => 'X', 'qty' => '1', 'unit_price_minor' => '1', 'created_at_device' => now()]);
        });

        $taken = $this->orderRecord([], ['id' => $foreignOrder]);
        $takenLine = $this->orderRecord([$this->orderLine(['id' => $foreignLine]), $this->orderLine(['name' => 'Fine'])], ['table_id' => $this->t2->id]);
        $good = $this->orderRecord([$this->orderLine()], ['table_id' => $this->t3->id]);

        $response = $this->sync([$taken, $takenLine, $good])->assertOk();

        $this->assertSame('id_conflict', $response->json('results.0.error.code'));
        $this->assertSame(['rejected', 'stored'], array_column($response->json('results.1.lines'), 'status'));
        $this->assertSame('id_conflict', $response->json('results.1.lines.0.error.code'));
        $this->assertSame('stored', $response->json('results.2.status'));
    }

    public function test_a_clock_in_the_future_cannot_win_every_later_conflict(): void
    {
        $record = $this->orderRecord([$this->orderLine()], ['updated_at' => now()->addDay()->toIso8601String(), 'opened_at' => now()->addDays(2)->toIso8601String()]);
        $this->store($record);

        $order = Order::query()->sole();
        $this->assertTrue($order->header_at->lessThanOrEqualTo(now()->addMinutes(5)->addSecond()));
        $this->assertTrue($order->opened_at->lessThanOrEqualTo(now()->addMinutes(5)->addSecond()));
    }
}
