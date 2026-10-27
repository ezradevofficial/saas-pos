<?php

namespace Modules\Restaurant\Tests;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-03, REST-04, REST-06, REST-09, REST-10, REST-17, NFR-04: the till's
// open orders are uploaded idempotently; lines are unioned by id, headers
// are last-writer-wins, sent marks only move forward, and nothing the
// waiter rang is ever lost.
class OrderSyncTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    public function test_a_table_order_is_stored_and_the_same_record_again_changes_nothing(): void
    {
        $record = $this->orderRecord([$this->orderLine(), $this->orderLine(['name' => 'Burger 2', 'seat' => 2])]);
        $first = $this->store($record);

        $this->assertSame(1, $first['rev']);
        $this->assertSame('stored', $first['lines'][0]['status']);
        $this->assertSame('open', $first['order']['status']);
        $this->assertCount(2, $first['order']['lines']);
        // 2 x KES 800.00 plus 12.5 % tax.
        $this->assertSame('180000', $first['order']['bill']['total_minor']);

        $again = $this->store($record);
        $this->assertSame(1, $again['rev'], 'a resend is harmless and changes nothing');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, OrderLine::query()->count());
    }

    public function test_lines_are_unioned_by_id_and_never_dropped(): void
    {
        $line1 = $this->orderLine();
        $record = $this->orderRecord([$line1]);
        $this->store($record);

        // Another till (or a later sync) knows a second line only; the first line is absent from this record.
        $line2 = $this->orderLine(['name' => 'Fries']);
        $record['lines'] = [$line2];
        $result = $this->store($record);

        $this->assertCount(2, $result['order']['lines']);
        $this->assertSame(2, OrderLine::query()->count());
    }

    public function test_the_header_is_last_writer_wins_and_a_stale_header_is_ignored(): void
    {
        $record = $this->orderRecord([$this->orderLine()], ['covers' => 2]);
        $this->store($record);

        $newer = array_replace($record, ['covers' => 5, 'table_id' => $this->t2->id, 'updated_at' => CarbonImmutable::now()->subMinutes(2)->toIso8601String()]);
        $this->assertSame(5, $this->store($newer)['order']['covers']);

        $stale = array_replace($record, ['covers' => 9, 'updated_at' => CarbonImmutable::now()->subMinutes(8)->toIso8601String()]);
        $result = $this->store($stale);
        $this->assertSame(5, $result['order']['covers']);
        $this->assertSame($this->t2->id, $result['order']['table_id']);
    }

    public function test_a_table_opened_twice_offline_is_kept_and_flagged_not_refused(): void
    {
        $this->store($this->orderRecord([$this->orderLine()]));
        $second = $this->store($this->orderRecord([$this->orderLine()]));

        $this->assertSame([['code' => 'table_shared']], $second['flags']);
        $this->assertSame(2, Order::query()->where('status', 'open')->count());
    }

    public function test_a_bar_tab_needs_no_table_and_stays_open_across_rounds(): void
    {
        $tab = $this->orderRecord([$this->sent($this->orderLine(['item_id' => $this->beer->id, 'name' => 'Beer', 'unit_price_minor' => '35000']))], ['kind' => 'tab', 'table_id' => null, 'tab_name' => 'Otieno']);
        $this->store($tab);

        $tab['lines'][] = $this->sent($this->orderLine(['item_id' => $this->beer->id, 'name' => 'Beer', 'unit_price_minor' => '35000']), 2);
        $tab['updated_at'] = CarbonImmutable::now()->subMinutes(1)->toIso8601String();
        $result = $this->store($tab);

        $this->assertSame('tab', $result['order']['kind']);
        $this->assertSame('Otieno', $result['order']['tab_name']);
        $this->assertSame('open', $result['order']['status']);
        $this->assertCount(2, $result['order']['lines']);
        // Two rounds, two tickets, both for the bar.
        $this->assertSame(2, Ticket::query()->where('station_id', $this->bar->id)->count());
    }

    public function test_sending_makes_one_ticket_per_station_and_course_routed_by_category(): void
    {
        $record = $this->orderRecord([
            $this->sent($this->orderLine()),
            $this->sent($this->orderLine(['item_id' => $this->beer->id, 'name' => 'Beer', 'unit_price_minor' => '35000'])),
            $this->sent($this->orderLine(['name' => 'Dessert', 'course' => 2])),
            $this->orderLine(['name' => 'Held course 3', 'course' => 3]),
        ]);
        $result = $this->store($record);

        $this->assertSame(3, Ticket::query()->count());
        $this->assertSame(2, Ticket::query()->where('station_id', $this->kitchen->id)->count(), 'courses 1 and 2 of the kitchen');
        $this->assertSame(1, Ticket::query()->where('station_id', $this->bar->id)->count());
        $this->assertSame([1, 2, 3], Ticket::query()->orderBy('number')->pluck('number')->all(), 'numbers run per location and day');
        $this->assertCount(3, $result['order']['tickets']);
        $held = collect($result['order']['lines'])->firstWhere('name', 'Held course 3');
        $this->assertNull($held['sent_at'], 'a course not fired stays on the order');

        // The same record again makes no new ticket.
        $this->store($record);
        $this->assertSame(3, Ticket::query()->count());
    }

    public function test_unrouted_categories_go_to_the_default_station_and_without_one_to_no_station(): void
    {
        $other = $this->inTenant(fn () => Item::create(['code' => 'GIFT', 'name' => 'Gift', 'type' => 'non_stock', 'base_uom_id' => $this->each->id]));
        $this->store($this->orderRecord([$this->sent($this->orderLine(['item_id' => $other->id, 'name' => 'Gift']))]));
        $this->assertSame($this->kitchen->id, Ticket::query()->sole()->station_id);

        $this->inTenant(function () {
            $this->kitchen->is_default = false;
            $this->kitchen->save();
        });
        $this->store($this->orderRecord([$this->sent($this->orderLine(['item_id' => $other->id, 'name' => 'Gift']))], ['table_id' => $this->t2->id]));
        $this->assertNull(Ticket::query()->orderByDesc('number')->first()->station_id);
    }

    public function test_a_sent_line_cannot_change_but_an_unsent_one_can(): void
    {
        $unsent = $this->orderLine();
        $sent = $this->sent($this->orderLine(['name' => 'Steak']));
        $record = $this->orderRecord([$unsent, $sent]);
        $this->store($record);

        $record['lines'][0]['qty'] = '3';
        $record['lines'][1]['qty'] = '3';
        $result = $this->store($record);

        $this->assertSame('stored', $result['lines'][0]['status']);
        $this->assertSame('rejected', $result['lines'][1]['status']);
        $this->assertSame('line_changed', $result['lines'][1]['error']['code']);
        $this->assertSame('3', collect($result['order']['lines'])->firstWhere('id', $unsent['id'])['qty']);
        $this->assertSame('1', collect($result['order']['lines'])->firstWhere('id', $sent['id'])['qty']);
    }

    public function test_a_resend_makes_one_ticket_by_its_id(): void
    {
        $line = $this->sent($this->orderLine());
        $record = $this->orderRecord([$line]);
        $this->store($record);

        $record['resends'] = [['id' => $this->id(), 'line_ids' => [$line['id']], 'at' => now()->toIso8601String(), 'by' => $this->waiter->id]];
        $this->store($record);
        $this->store($record);

        $this->assertSame(['order', 'resend'], Ticket::query()->orderBy('number')->pluck('kind')->all());
    }

    public function test_a_closed_order_refuses_new_lines_but_keeps_the_ones_it_has(): void
    {
        $line = $this->orderLine();
        $record = $this->orderRecord([$line]);
        $this->store($record);
        $this->inTenant(fn () => Order::query()->update(['status' => 'closed', 'closed_at' => now()]));

        $record['lines'] = [$line, $this->orderLine(['name' => 'Late'])];
        $result = $this->store($record);

        $this->assertSame('stored', $result['lines'][0]['status']);
        $this->assertSame('rejected', $result['lines'][1]['status']);
        $this->assertSame('order_closed', $result['lines'][1]['error']['code']);
        $this->assertSame(1, OrderLine::query()->count());
    }

    public function test_a_cancelled_empty_order_is_cancelled_and_a_sent_one_is_not(): void
    {
        $empty = $this->orderRecord([$this->orderLine()]);
        $this->store($empty);
        $empty['status'] = 'cancelled';
        $this->assertSame('cancelled', $this->store($empty)['order']['status']);

        $busy = $this->orderRecord([$this->sent($this->orderLine())], ['table_id' => $this->t2->id]);
        $this->store($busy);
        $busy['status'] = 'cancelled';
        $result = $this->store($busy);
        $this->assertSame('open', $result['order']['status']);
        $this->assertSame([['code' => 'cancel_refused']], $result['flags']);
    }

    public function test_a_merge_done_at_the_till_moves_lines_and_covers(): void
    {
        $a = $this->orderRecord([$this->sent($this->orderLine(['name' => 'A1']))], ['covers' => 2]);
        $b = $this->orderRecord([$this->sent($this->orderLine(['name' => 'B1']))], ['table_id' => $this->t2->id, 'covers' => 3]);
        $this->store($a);
        $this->store($b);

        $b['merged_into_id'] = $a['id'];
        $b['updated_at'] = now()->toIso8601String();
        $this->store($b);

        $this->assertSame('merged', Order::query()->find($b['id'])->status);
        $target = Order::query()->find($a['id']);
        $this->assertSame(5, $target->covers);
        $this->assertSame(2, OrderLine::query()->where('order_id', $a['id'])->count());
        $this->assertSame(2, Ticket::query()->where('order_id', $a['id'])->count(), 'tickets follow the lines');
    }

    public function test_a_line_of_another_order_is_never_taken_over(): void
    {
        $line = $this->orderLine();
        $a = $this->orderRecord([$line]);
        $b = $this->orderRecord([], ['table_id' => $this->t2->id]);
        $this->store($a);
        $this->store($b);

        $b['lines'] = [$line];
        $result = $this->store($b);

        $this->assertSame('line_conflict', $result['lines'][0]['error']['code']);
        $this->assertSame($a['id'], OrderLine::query()->find($line['id'])->order_id);
    }

    public function test_a_transfer_by_someone_without_the_right_is_kept_and_flagged(): void
    {
        $record = $this->orderRecord([$this->orderLine()]);
        $this->store($record);
        $record['waiter_id'] = $this->cook->id;
        $record['updated_by'] = $this->cook->id;
        $record['updated_at'] = now()->toIso8601String();
        $result = $this->store($record);

        $this->assertSame($this->cook->id, $result['order']['waiter_id']);
        $this->assertSame([['code' => 'transfer_unauthorised']], $result['flags']);

        // A waiter holds the right, so a transfer back by a waiter is not flagged again.
        $this->assertSame(1, collect($result['flags'])->count());
    }

    public function test_unknown_references_are_refused_per_order_and_never_block_the_rest(): void
    {
        $other = $this->otherTenant();
        $bad = $this->orderRecord([], ['waiter_id' => $other['user']->id]);
        $noTable = $this->orderRecord([], ['table_id' => $this->id()]);
        $good = $this->orderRecord([$this->orderLine()], ['table_id' => $this->t3->id]);

        $response = $this->sync([$bad, $noTable, $good])->assertOk();
        $this->assertSame('waiter_unknown', $response->json('results.0.error.code'));
        $this->assertSame('table_unknown', $response->json('results.1.error.code'));
        $this->assertSame('stored', $response->json('results.2.status'));

        $this->sync([$bad])->assertStatus(422)->assertJsonPath('code', 'upload_rejected');
    }

    public function test_an_order_naming_an_unknown_item_tax_code_or_merge_target_is_refused_whole(): void
    {
        $unknownItem = $this->orderRecord([$this->orderLine(['item_id' => $this->id()])]);
        $unknownCode = $this->orderRecord([$this->orderLine(['tax_code_id' => $this->id()])], ['table_id' => $this->t2->id]);
        $unknownTarget = $this->orderRecord([$this->orderLine()], ['table_id' => $this->t3->id, 'merged_into_id' => $this->id()]);

        $response = $this->sync([$unknownItem, $unknownCode, $unknownTarget])->assertStatus(422);

        $this->assertSame(['item_unknown', 'tax_code_unknown', 'order_unknown'], array_column(array_column($response->json('results'), 'error'), 'code'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderLine::query()->count());
    }

    public function test_another_locations_order_id_is_a_conflict(): void
    {
        $record = $this->orderRecord([$this->orderLine()]);
        $this->store($record);
        [, $token] = $this->pairedTill($this->locationB, 'Till B');

        $result = $this->sync([$record], $token)->assertStatus(422)->json('results.0');
        $this->assertSame('id_conflict', $result['error']['code']);
    }

    public function test_the_module_must_be_active_and_the_token_a_devices(): void
    {
        $record = $this->orderRecord([$this->orderLine()]);
        $this->postJson('/api/v1/restaurant/orders/sync', ['orders' => [$record]], $this->headersFor())->assertForbidden();
        $this->sync([])->assertUnprocessable();

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('restaurant'));
        $this->sync([$record])->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }

    public function test_an_order_with_a_wrong_shape_is_a_validation_error(): void
    {
        $record = $this->orderRecord([$this->orderLine(['qty' => 'two'])]);
        $this->sync([$record])->assertUnprocessable()->assertJsonValidationErrors(['orders.0.lines.0.qty']);
    }
}
