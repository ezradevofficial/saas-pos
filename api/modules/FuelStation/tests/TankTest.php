<?php

namespace Modules\FuelStation\Tests;

use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Event;
use Modules\FuelStation\Approvals\TankVarianceType;
use Modules\FuelStation\Models\Reconciliation;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Tanks\Reconciliations;
use Modules\FuelStation\Tanks\Strapping;
use Modules\FuelStation\Tests\Concerns\BuildsFuel;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\Contracts\StockGained;
use Modules\Inventory\Contracts\StockWrittenOff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FUEL-06, FUEL-23 to FUEL-26: strapping, dips, reconciliation, deliveries and loss or gain.
class TankTest extends TestCase
{
    use BuildsFuel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFuel();
    }

    public function test_a_dip_converts_by_linear_interpolation_and_outside_the_chart_is_refused(): void
    {
        $points = [[BigDecimal::of('0'), BigDecimal::of('0')], [BigDecimal::of('50'), BigDecimal::of('1000')], [BigDecimal::of('100'), BigDecimal::of('3000')]];
        $this->assertSame('2000.000', (string) Strapping::interpolate($points, BigDecimal::of('75')));
        $this->assertSame('1000.000', (string) Strapping::interpolate($points, BigDecimal::of('50')));
        // 10.01 cm: 1000 / 50 * 10.01 = 200.200
        $this->assertSame('200.200', (string) Strapping::interpolate($points, BigDecimal::of('10.01')));
        // 33.33 cm of the second span: 20 L per cm... first span 20 L/cm: 666.600
        $this->assertSame('666.600', (string) Strapping::interpolate($points, BigDecimal::of('33.33')));

        $this->getJson("/api/v1/fuel/tanks/{$this->tank->id}/dip-convert?dip_cm=75", $this->headersFor($this->supervisor))->assertOk()->assertJsonPath('data.litres', '7500.000');
        $this->getJson("/api/v1/fuel/tanks/{$this->tank->id}/dip-convert?dip_cm=101", $this->headersFor($this->supervisor))->assertStatus(422)->assertJsonPath('code', 'dip_out_of_range');
    }

    public function test_a_chart_must_rise_and_stay_within_capacity(): void
    {
        $url = "/api/v1/fuel/tanks/{$this->tank->id}/strapping";
        $this->postJson($url, ['points' => [['dip_cm' => '0', 'litres' => '0'], ['dip_cm' => '10', 'litres' => '10000.001']]], $this->headersFor($this->manager))->assertStatus(422)->assertJsonPath('code', 'chart_above_capacity');
        $this->postJson($url, ['points' => [['dip_cm' => '0', 'litres' => '0'], ['dip_cm' => '10', 'litres' => '500'], ['dip_cm' => '20', 'litres' => '500']]], $this->headersFor($this->manager))->assertStatus(422)->assertJsonPath('code', 'chart_not_rising');
        $this->postJson($url, ['points' => [['dip_cm' => '0', 'litres' => '0']]], $this->headersFor($this->manager))->assertStatus(422);
        $this->postJson($url, ['points' => [['dip_cm' => '0', 'litres' => '0'], ['dip_cm' => '200', 'litres' => '10000']]], $this->headersFor($this->manager))->assertCreated()->assertJsonCount(2, 'data.points');
        $this->assertSame(1, $this->audited('fuel.strapping_chart.points'));
    }

    public function test_the_first_dip_sets_the_baseline_and_a_loss_within_tolerance_is_posted(): void
    {
        Event::fake([StockWrittenOff::class, StockGained::class]);
        $first = $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '50', 'taken_at' => now()->subHours(3)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()
            ->assertJsonPath('data.litres', '5000.000')->assertJsonPath('data.reconciliation.status', 'baseline')->json('data');
        $this->assertNotEmpty($first['reconciliation']['number']);
        $this->inTenant(fn () => $this->assertSame('5000.000', Tank::query()->findOrFail($this->tank->id)->baseline_litres));

        // 2 L lost against a 5 L tolerance: accepted and written off.
        $this->inTenant(fn () => Tank::query()->whereKey($this->tank->id)->update(['tolerance_litres' => '5.000']));
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '49.96', 'taken_at' => now()->subHours(2)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()
            ->assertJsonPath('data.litres', '4996.000')->assertJsonPath('data.reconciliation.status', 'accepted')->assertJsonPath('data.reconciliation.variance_litres', '-4.000');
        Event::assertDispatched(StockWrittenOff::class, fn ($e) => $e->source === 'fuel.tank_loss' && $e->lines[0]['qty'] === '4.000' && $e->lines[0]['item_id'] === $this->petrol->id);
        Event::assertNotDispatched(StockGained::class);

        // A dip older than the last reconciled one is refused.
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '49', 'taken_at' => now()->subHours(5)->toIso8601String()], $this->headersFor($this->supervisor))->assertStatus(422)->assertJsonPath('code', 'dip_out_of_order');
    }

    public function test_a_loss_beyond_tolerance_waits_for_approval_by_someone_else_and_a_gain_enters_stock(): void
    {
        Event::fake([StockWrittenOff::class, StockGained::class]);
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '50', 'taken_at' => now()->subHours(3)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated();
        $loss = $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '49', 'taken_at' => now()->subHours(2)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()
            ->assertJsonPath('data.reconciliation.status', 'pending_approval')->json('data.reconciliation');
        Event::assertNotDispatched(StockWrittenOff::class);

        $this->inTenant(function () use ($loss) {
            $type = new TankVarianceType;
            $this->assertFalse($type->mayAct($this->supervisor, $loss['id'], 'approve'));
            $this->assertTrue($type->mayAct($this->manager, $loss['id'], 'approve'));
            $this->assertSame('running', app(WorkflowEngine::class)->current(TankVarianceType::KEY, $loss['id'])->status);
            // The baseline moved to the dip whatever the decision.
            $this->assertSame('4900.000', Tank::query()->findOrFail($this->tank->id)->baseline_litres);
            app(Reconciliations::class)->completed($loss['id'], 'approved', $this->manager->id);
            $this->assertSame(Reconciliation::APPROVED, Reconciliation::query()->findOrFail($loss['id'])->status);
        });
        Event::assertDispatched(StockWrittenOff::class, fn ($e) => $e->lines[0]['qty'] === '100.000');

        // A gain of 200 L is posted to Inventory on approval too.
        $gain = $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '51', 'taken_at' => now()->subHour()->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()->json('data.reconciliation');
        $this->assertSame('200.000', $gain['variance_litres']);
        $this->inTenant(fn () => app(Reconciliations::class)->completed($gain['id'], 'approved', $this->manager->id));
        Event::assertDispatched(StockGained::class, fn ($e) => $e->lines[0]['qty'] === '200.000');

        // A rejected one posts nothing but the case is kept.
        $rejected = $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '50', 'taken_at' => now()->subMinutes(30)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()->json('data.reconciliation');
        Event::fake([StockWrittenOff::class, StockGained::class]);
        $this->inTenant(fn () => app(Reconciliations::class)->completed($rejected['id'], 'rejected', $this->manager->id));
        Event::assertNothingDispatched();
        $this->getJson('/api/v1/fuel/reconciliations?status=rejected', $this->headersFor($this->manager))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sales_and_deliveries_are_counted_once_in_the_expected_stock(): void
    {
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '50', 'taken_at' => now()->subHours(3)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated();

        // 20 L sold at nozzle A; a delivery of 1,000 L noted (dip 50 -> 60 cm = 5,000 -> 6,000 L).
        $fuelShift = $this->id();
        $this->uploadShifts([$this->fuelShiftBody($fuelShift)])->assertOk();
        $this->upload([$this->fuelSaleBody($fuelShift, $this->posShift(), '20.000')])->assertOk();
        $delivery = $this->postJson('/api/v1/fuel/deliveries', [
            'tank_id' => $this->tank->id, 'note_number' => 'DN-001', 'note_litres' => '1005.000', 'before_dip_cm' => '50', 'after_dip_cm' => '60', 'after_taken_at' => now()->subHour()->toIso8601String(),
            'unit_cost_minor' => 15000, 'currency' => 'KES',
        ], $this->headersFor($this->supervisor))->assertCreated()->assertJsonPath('data.received_litres', '1000.000')->assertJsonPath('data.shortage_litres', '5.000')->assertJsonPath('data.cost_minor', '15000000')->json('data');
        $this->assertSame('delivery_short', $delivery['flags'][0]['code']);
        $this->postJson('/api/v1/fuel/deliveries', [
            'tank_id' => $this->tank->id, 'note_number' => 'dn-001', 'note_litres' => '1.000', 'before_dip_cm' => '50', 'after_dip_cm' => '60', 'unit_cost_minor' => 1, 'currency' => 'KES',
        ], $this->headersFor($this->supervisor))->assertStatus(422)->assertJsonPath('code', 'delivery_note_used');
        $this->getJson("/api/v1/fuel/tanks/{$this->tank->id}", $this->headersFor($this->supervisor))->assertOk()->assertJsonPath('data.book_litres', '5980.000');

        // The dip shows 5,990 L, 10 L more than the book: expected 5,980.000, variance 10.000, beyond a zero tolerance.
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '59.9', 'taken_at' => now()->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()
            ->assertJsonPath('data.reconciliation.expected_litres', '5980.000')->assertJsonPath('data.reconciliation.sales_litres', '20.000')->assertJsonPath('data.reconciliation.deliveries_litres', '1000.000')
            ->assertJsonPath('data.reconciliation.variance_litres', '10.000')->assertJsonPath('data.reconciliation.status', 'pending_approval');

        // Counted: the book now starts from the dip.
        $this->getJson("/api/v1/fuel/tanks/{$this->tank->id}", $this->headersFor($this->supervisor))->assertOk()->assertJsonPath('data.book_litres', '5990.000');
    }

    public function test_a_delivery_posts_the_measured_litres_to_inventory(): void
    {
        Event::fake([GoodsReceived::class]);
        $this->postJson('/api/v1/fuel/deliveries', [
            'tank_id' => $this->tank->id, 'note_number' => 'DN-9', 'note_litres' => '2000.000', 'before_dip_cm' => '10', 'after_dip_cm' => '30', 'sold_during_litres' => '500', 'unit_cost_minor' => 14000, 'currency' => 'KES',
        ], $this->headersFor($this->supervisor))->assertCreated()->assertJsonPath('data.received_litres', '2000.000')->assertJsonPath('data.sold_during_litres', '0.000');
        Event::assertDispatched(GoodsReceived::class, fn ($e) => $e->lines[0]['qty'] === '2000.000' && $e->lines[0]['item_id'] === $this->petrol->id && $e->locationId === $this->locationA->id);
    }
}
