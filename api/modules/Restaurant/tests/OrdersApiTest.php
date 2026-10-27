<?php

namespace Modules\Restaurant\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Setting;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-02, REST-04, REST-05, REST-14, REST-15, RBAC-04: orders in the back
// office are read at the places the reader reaches; transfers, merges,
// cancellations and split previews follow the rules of the till.
class OrdersApiTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    /** @return array{0: array, 1: array} a table order of two lines and a tab with one */
    private function twoOrders(): array
    {
        $table = $this->orderRecord([$this->sent($this->orderLine(['seat' => 1])), $this->orderLine(['name' => 'Salad', 'unit_price_minor' => '40000', 'seat' => 2])]);
        $tab = $this->orderRecord([$this->sent($this->orderLine(['item_id' => $this->beer->id, 'name' => 'Beer', 'unit_price_minor' => '35000']))], ['kind' => 'tab', 'table_id' => null, 'tab_name' => 'Otieno', 'covers' => 1]);
        $this->store($table);
        $this->store($tab);

        return [$table, $tab];
    }

    public function test_orders_are_listed_without_lines_and_filtered(): void
    {
        [$table, $tab] = $this->twoOrders();

        $list = $this->api('GET', 'restaurant/orders')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->assertArrayNotHasKey('lines', $list->json('data.0'));
        $this->assertSame('135000', collect($list->json('data'))->firstWhere('id', $table['id'])['bill']['total_minor'], 'KES 800 + KES 400 + 12.5 % tax = KES 1,350.00');

        $this->api('GET', 'restaurant/orders?kind=tab')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tab_name', 'Otieno');
        $this->api('GET', 'restaurant/orders?search=otie')->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', 'restaurant/orders?search=T1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $table['id']);
        $this->api('GET', "restaurant/orders?waiter_id={$this->cook->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'restaurant/orders?status=closed')->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'restaurant/orders?status=all&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
        $this->api('GET', 'restaurant/orders?status=bogus')->assertUnprocessable();

        $this->api('GET', "restaurant/orders/{$tab['id']}")->assertOk()
            ->assertJsonPath('data.lines.0.name', 'Beer')->assertJsonPath('data.tickets.0.station_name', 'Bar')->assertJsonPath('data.lines.0.ticket_status', 'new')
            ->assertJsonPath('data.waiter_name', $this->waiter->name);
    }

    public function test_orders_are_read_only_at_the_places_the_reader_reaches(): void
    {
        [$table] = $this->twoOrders();
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));

        $this->api('GET', 'restaurant/orders', [], $managerB)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "restaurant/orders/{$table['id']}", [], $managerB)->assertForbidden();
        $this->api('GET', "restaurant/orders/{$table['id']}", [], $this->waiter)->assertOk();
        $this->api('GET', "restaurant/orders/{$table['id']}", [], $this->cook)->assertForbidden();
        $this->api('GET', 'restaurant/orders', [], $this->cook)->assertForbidden();

        $other = $this->otherRestaurantTenant();
        $this->json('GET', "/api/v1/restaurant/orders/{$table['id']}", [], $this->headersFor($other['user']))->assertNotFound();
        $this->json('GET', '/api/v1/restaurant/orders', [], $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_tables_show_the_status_derived_from_their_orders(): void
    {
        [$table] = $this->twoOrders();
        $t1 = fn () => collect($this->api('GET', "restaurant/tables?location_id={$this->locationA->id}")->json('data'))->firstWhere('id', $this->t1->id);

        $this->assertSame('occupied', $t1()['status']);
        $this->assertSame($table['id'], $t1()['order']['id']);
        $this->assertSame('135000', $t1()['order']['total_minor']);
        $this->assertSame($this->waiter->name, $t1()['order']['waiter_name']);

        $table['bill_requested_at'] = now()->subMinute()->toIso8601String();
        $table['updated_at'] = now()->toIso8601String();
        $this->store($table);
        $this->assertSame('bill', $t1()['status']);

        $this->inTenant(fn () => Order::query()->update(['status' => 'closed', 'closed_at' => now()]));
        $this->assertSame('free', $t1()['status']);
        $this->api('POST', "restaurant/tables/{$this->t1->id}/status", ['status' => 'cleaning']);
        $this->assertSame('cleaning', $t1()['status']);
    }

    public function test_a_table_in_use_cannot_be_archived(): void
    {
        $this->twoOrders();

        $this->api('POST', "restaurant/tables/{$this->t1->id}/archive")->assertConflict()->assertJsonPath('code', 'table_in_use');
    }

    public function test_an_order_moves_to_another_waiter_and_it_is_audited(): void
    {
        [$table] = $this->twoOrders();
        $colleague = $this->userWith('waiter', Scope::location($this->locationA->id));

        $this->api('POST', "restaurant/orders/{$table['id']}/transfer", ['waiter_id' => $colleague->id], $this->waiter)->assertOk()->assertJsonPath('data.waiter_id', $colleague->id);

        $entry = AuditEntry::query()->where('action', 'restaurant.order.transfer')->sole();
        $this->assertSame($this->waiter->id, $entry->before['waiter_id']);
        $this->assertSame($colleague->id, $entry->after['waiter_id']);
        $this->assertSame($this->waiter->id, $entry->user_id);

        $this->api('POST', "restaurant/orders/{$table['id']}/transfer", ['waiter_id' => $this->id()])->assertUnprocessable()->assertJsonPath('code', 'waiter_unknown');
        $this->api('POST', "restaurant/orders/{$table['id']}/transfer", [])->assertUnprocessable();
        $this->api('POST', "restaurant/orders/{$table['id']}/transfer", ['waiter_id' => $colleague->id], $this->cook)->assertForbidden();
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->api('POST', "restaurant/orders/{$table['id']}/transfer", ['waiter_id' => $colleague->id], $cashier)->assertForbidden();
    }

    public function test_two_tables_are_merged_with_their_lines_covers_and_tickets(): void
    {
        [$table, $tab] = $this->twoOrders();
        $b = $this->orderRecord([$this->sent($this->orderLine(['name' => 'Pizza']))], ['table_id' => $this->t2->id, 'covers' => 3]);
        $this->store($b);

        $merged = $this->api('POST', "restaurant/orders/{$b['id']}/merge", ['into_order_id' => $table['id']])->assertOk()->json('data');

        $this->assertSame($table['id'], $merged['id']);
        $this->assertSame(5, $merged['covers']);
        $this->assertCount(3, $merged['lines']);
        $this->assertCount(2, $merged['tickets']);
        $this->api('GET', "restaurant/orders/{$b['id']}")->assertOk()->assertJsonPath('data.status', 'merged')->assertJsonPath('data.merged_into_id', $table['id'])->assertJsonCount(0, 'data.lines');
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.order.merge')->count());

        // A merged order is closed to everything, onto itself and across places.
        $this->api('POST', "restaurant/orders/{$b['id']}/merge", ['into_order_id' => $tab['id']])->assertConflict()->assertJsonPath('code', 'order_closed');
        $this->api('POST', "restaurant/orders/{$table['id']}/merge", ['into_order_id' => $table['id']])->assertUnprocessable()->assertJsonPath('code', 'merge_invalid');
        $this->api('POST', "restaurant/orders/{$table['id']}/merge", ['into_order_id' => $this->id()])->assertNotFound();
    }

    public function test_orders_of_two_locations_are_not_merged(): void
    {
        [$table] = $this->twoOrders();
        $elsewhere = $this->inTenant(fn () => Order::create([
            'company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'location_id' => $this->locationB->id, 'kind' => 'tab', 'tab_name' => 'B', 'waiter_id' => $this->waiter->id,
            'currency' => 'KES', 'opened_at' => now(), 'header_at' => now(),
        ]));

        $this->api('POST', "restaurant/orders/{$table['id']}/merge", ['into_order_id' => $elsewhere->id])->assertUnprocessable()->assertJsonPath('code', 'merge_invalid');
        // The manager of branch A merges at A; the target must also be one they reach.
        $this->api('POST', "restaurant/orders/{$table['id']}/merge", ['into_order_id' => $elsewhere->id], $this->manager)->assertNotFound();
        // A waiter does not merge tables at all.
        $this->api('POST', "restaurant/orders/{$table['id']}/merge", ['into_order_id' => $elsewhere->id], $this->waiter)->assertForbidden();
    }

    public function test_an_order_with_nothing_sent_or_paid_can_be_cancelled(): void
    {
        $empty = $this->orderRecord([$this->orderLine()], ['table_id' => $this->t3->id]);
        [$table] = $this->twoOrders();
        $this->store($empty);

        $this->api('POST', "restaurant/orders/{$empty['id']}/cancel", ['reason' => 'Guests left'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api('POST', "restaurant/orders/{$table['id']}/cancel", ['reason' => 'Guests left'])->assertConflict()->assertJsonPath('code', 'order_not_empty');
        $this->api('POST', "restaurant/orders/{$empty['id']}/cancel", ['reason' => 'Again'])->assertConflict()->assertJsonPath('code', 'order_closed');
        $this->api('POST', "restaurant/orders/{$table['id']}/cancel", [])->assertUnprocessable();
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.order.cancel')->count());
    }

    public function test_the_split_preview_shares_the_bill_to_the_unit(): void
    {
        [$table] = $this->twoOrders();
        $this->inTenant(fn () => Setting::create(['company_id' => $this->acme->id, 'service_charge_percent' => '10', 'service_charge_item_id' => $this->serviceItem->id]));
        $lines = collect($this->api('GET', "restaurant/orders/{$table['id']}")->json('data.lines'));
        $whole = $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'full'])->assertOk()->json('data.parts');
        // Lines KES 1,350.00 with tax on a net of KES 1,200.00; service charge 10 % of the net, KES 120.00, taxed as its item (12.5 %).
        $this->assertSame(['135000', '12000', '1500', '148500'], [$whole[0]['lines_minor'], $whole[0]['service_charge_minor'], $whole[0]['service_tax_minor'], $whole[0]['total_minor']]);

        $equal = $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'equal', 'parts' => 3, 'tip_minor' => '1000'])->assertOk()->json('data.parts');
        $this->assertCount(3, $equal);
        $this->assertSame(149500, array_sum(array_column($equal, 'total_minor')));

        $groups = [[$lines[0]['id']], [$lines[1]['id']]];
        $byItem = $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'item', 'groups' => $groups])->assertOk()->json('data.parts');
        $this->assertSame([$lines[0]['id']], $byItem[0]['line_ids']);
        $this->assertSame('90000', $byItem[0]['lines_minor']);

        $bySeat = $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'seat', 'tip_percent' => '10'])->assertOk()->json('data.parts');
        $this->assertCount(2, $bySeat);
        $this->assertSame('13500', (string) array_sum(array_column($bySeat, 'tip_minor')), '10 % of the lines, shared by the seats');

        $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'item', 'groups' => [[$lines[0]['id']]]])->assertUnprocessable()->assertJsonPath('code', 'split_invalid');
        $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'equal', 'parts' => 1])->assertUnprocessable();
        $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'equal'])->assertUnprocessable()->assertJsonPath('code', 'split_invalid');
        $this->api('POST', "restaurant/orders/{$table['id']}/split", ['mode' => 'full'], $this->cook)->assertForbidden();
        $this->assertSame(0, OrderLine::query()->whereNotNull('voided_at')->count(), 'a preview changes nothing');
    }
}
