<?php

namespace Modules\Restaurant\Tests;

use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-18, RBAC-04: covers, spend per cover and per order, table turn time,
// waiter sales and voids after send, for a period and a place, at the
// locations the reader may see.
class ReportsTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->setUpRestaurant();
    }

    private function closed(string $table, int $covers, int $minutes, string $total, string $tip, $waiter, ?CarbonImmutable $closedAt = null, string $kind = 'table'): Order
    {
        return $this->inTenant(function () use ($table, $covers, $minutes, $total, $tip, $waiter, $closedAt, $kind) {
            $closedAt ??= CarbonImmutable::now()->subHour();
            $order = Order::create([
                'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'kind' => $kind,
                'table_id' => $kind === 'table' ? $this->{$table}->id : null, 'tab_name' => $kind === 'tab' ? 'Tab' : null, 'waiter_id' => $waiter->id,
                'covers' => $covers, 'currency' => 'KES', 'status' => 'closed', 'opened_at' => $closedAt->subMinutes($minutes), 'closed_at' => $closedAt, 'header_at' => $closedAt,
            ]);
            Settlement::create(['order_id' => $order->id, 'location_id' => $this->locationA->id, 'sale_id' => $this->id(), 'mode' => 'full', 'final' => true, 'currency' => 'KES', 'total_minor' => $total, 'tip_minor' => $tip, 'settled_at' => $closedAt]);

            return $order;
        });
    }

    private function report(array $query = [], $user = null)
    {
        $query = array_replace(['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()], $query);

        return $this->api('GET', 'restaurant/reports/summary?'.http_build_query($query), [], $user);
    }

    public function test_covers_spend_turn_time_and_waiter_sales(): void
    {
        $this->closed('t1', 2, 60, '100000', '5000', $this->waiter);
        $this->closed('t2', 4, 90, '300000', '15000', $this->waiter);
        $this->closed('t3', 1, 30, '50000', '0', $this->manager, kind: 'tab');
        // Outside the period, and a sale that was voided: neither counts.
        $old = $this->closed('t1', 9, 10, '999999', '0', $this->waiter, CarbonImmutable::now()->subDays(10));
        $voided = $this->closed('t2', 9, 10, '888888', '0', $this->waiter);
        $this->inTenant(fn () => Settlement::query()->where('order_id', $voided->id)->update(['voided_at' => now()]));

        $data = $this->report()->assertOk()->json('data');

        $kes = $data['currencies']['KES'];
        $this->assertSame([4, 16, '450000', '20000'], [$kes['orders'], $kes['covers'], $kes['sales_minor'], $kes['tips_minor']], 'the voided order still counts as an order, its sale does not');
    }

    public function test_the_numbers_of_a_clean_day(): void
    {
        $this->closed('t1', 2, 60, '100000', '5000', $this->waiter);
        $this->closed('t2', 4, 90, '300000', '15000', $this->waiter);
        $this->closed('t3', 1, 30, '50000', '0', $this->manager, kind: 'tab');

        $data = $this->report()->assertOk()->json('data');

        $kes = $data['currencies']['KES'];
        $this->assertSame([3, 7, '450000', '20000'], [$kes['orders'], $kes['covers'], $kes['sales_minor'], $kes['tips_minor']]);
        $this->assertSame('64286', $kes['avg_per_cover_minor'], '450000 / 7 covers, half up');
        $this->assertSame('150000', $kes['avg_per_order_minor']);
        $this->assertSame(75, $data['avg_turn_minutes'], 'tables only: (60 + 90) / 2');
        $this->assertSame(now()->subDay()->toDateString(), $data['from']);

        $waiters = collect($data['waiters']);
        $this->assertSame($this->waiter->id, $waiters->first()['waiter_id'], 'the best seller first');
        $this->assertSame([2, 6, '400000', '20000', $this->waiter->name], [$waiters[0]['orders'], $waiters[0]['covers'], $waiters[0]['sales_minor'], $waiters[0]['tips_minor'], $waiters[0]['name']]);
        $this->assertSame('50000', $waiters[1]['sales_minor']);
    }

    public function test_voids_after_send_are_counted_by_person_and_reason(): void
    {
        $order = $this->closed('t1', 2, 60, '100000', '0', $this->waiter);
        $this->inTenant(function () use ($order) {
            $make = fn (array $attrs) => OrderLine::create(array_replace([
                'order_id' => $order->id, 'location_id' => $this->locationA->id, 'item_id' => $this->burger->id, 'name' => 'Burger', 'qty' => '1', 'unit_price_minor' => '80000',
                'tax_rate' => '12.5', 'tax_inclusive' => false, 'course' => 1, 'created_at_device' => now()->subHours(2), 'sent_at' => now()->subHours(2),
                'voided_at' => now()->subMinutes(30), 'voided_by' => $this->manager->id, 'void_after_send' => true, 'void_reason' => 'Guest left', 'modifiers' => [['name' => 'x', 'price_minor' => '10000']],
            ], $attrs));
            $make([]);
            $make(['void_reason' => 'Wrong dish', 'qty' => '2', 'modifiers' => []]);
            $make(['void_after_send' => false, 'void_reason' => 'Changed mind']);
        });

        $data = $this->report()->assertOk()->json('data');

        // (80000 + 10000) x 1.125 = 101250; 2 x 80000 x 1.125 = 180000: unsent voids are not "after send".
        $this->assertSame([['user_id' => $this->manager->id, 'name' => $this->manager->name, 'currency' => 'KES', 'count' => 2, 'value_minor' => '281250']], $data['voids']);
        $this->assertEqualsCanonicalizing([['reason' => 'Guest left', 'count' => 1], ['reason' => 'Wrong dish', 'count' => 1]], $data['void_reasons']);
    }

    public function test_the_report_is_narrowed_by_place_and_by_what_the_reader_reaches(): void
    {
        $this->closed('t1', 2, 60, '100000', '0', $this->waiter);
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));

        $this->assertSame('100000', $this->report(['location_id' => $this->locationA->id])->assertOk()->json('data.currencies.KES.sales_minor'));
        $this->assertSame('100000', $this->report(['branch_id' => $this->branchA->id])->assertOk()->json('data.currencies.KES.sales_minor'));
        $this->assertSame('100000', $this->report(['company_id' => $this->acme->id])->assertOk()->json('data.currencies.KES.sales_minor'));
        $this->assertSame([], (array) $this->report(['branch_id' => $this->branchB->id])->assertOk()->json('data.currencies'));

        // Branch B's manager sees none of A; asking for A by name is refused.
        $this->assertSame([], (array) $this->report([], $managerB)->assertOk()->json('data.currencies'));
        $this->report(['location_id' => $this->locationA->id], $managerB)->assertForbidden();
        $this->report([], $this->manager)->assertOk()->assertJsonPath('data.currencies.KES.orders', 1);
    }

    public function test_only_report_readers_see_it_and_other_tenants_see_nothing(): void
    {
        $this->closed('t1', 2, 60, '100000', '0', $this->waiter);

        $this->report([], $this->waiter)->assertForbidden();
        $this->report([], $this->cook)->assertForbidden();
        $this->report(['from' => 'yesterday'])->assertUnprocessable();
        $this->report(['to' => now()->subDays(5)->toDateString()])->assertUnprocessable();

        $other = $this->otherRestaurantTenant();
        $this->json('GET', '/api/v1/restaurant/reports/summary?from='.now()->subDay()->toDateString().'&to='.now()->toDateString(), [], $this->headersFor($other['user']))
            ->assertOk()->assertJsonPath('data.waiters', [])->assertJsonPath('data.avg_turn_minutes', null);
    }

    public function test_an_empty_period_has_no_averages(): void
    {
        $data = $this->report()->assertOk()->json('data');

        $this->assertSame([], (array) $data['currencies']);
        $this->assertNull($data['avg_turn_minutes']);
        $this->assertSame([], $data['voids']);
    }
}
