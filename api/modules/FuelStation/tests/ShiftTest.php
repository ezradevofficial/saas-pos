<?php

namespace Modules\FuelStation\Tests;

use App\Core\Workflow\Runtime\WorkflowEngine;
use Modules\FuelStation\Approvals\ShiftVarianceType;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Shifts\Settlements;
use Modules\FuelStation\Tests\Concerns\BuildsFuel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FUEL-09 to FUEL-17: shifts from the till, the meter maths, monotonic readings, close rules, variance approval, late sales and meter resets.
class ShiftTest extends TestCase
{
    use BuildsFuel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFuel();
    }

    public function test_a_shift_opens_and_closes_with_exact_meter_maths_and_zero_variance_is_accepted(): void
    {
        $this->allowLitres('1000', '10');
        $id = $this->id();
        // 100.000 L on nozzle A less a 5.000 L test = 95.000 L; B 50.000 L. Expected 145 L x KES 180.50 = KES 26,172.50.
        $closing = $this->fuelClosing(['A' => '1100.000', 'B' => '5050.000'], ['A' => 1714750, 'B' => 902500]);
        $closing['nozzles'][0]['test_litres'] = '5.000';
        $closing['tenders'][0]['tenders'][0]['amount_minor'] = '1714750';

        $this->uploadShifts([$this->fuelShiftBody($id, closing: $closing)])->assertOk()->assertJsonPath('results.0.status', 'stored')->assertJsonPath('results.0.state', 'closed');

        $this->inTenant(function () use ($id) {
            $a = Shift::query()->findOrFail($id)->shiftNozzles()->where('nozzle_id', $this->nozzleA->id)->first();
            $this->assertSame('95.000', $a->meter_litres);
            $this->assertSame(1714750, (int) $a->expected_minor);
            $s = Settlement::query()->where('attendant_id', $this->attendant->id)->first();
            $this->assertSame(Settlement::AUTO, $s->status);
            $this->assertSame(0, $s->variance_minor);
            $this->assertNotNull($s->number);
            $this->assertSame('1100.000', Nozzle::query()->findOrFail($this->nozzleA->id)->last_reading_litres);
        });
        $this->assertSame(1, $this->audited('fuel.shift.close'));
    }

    public function test_a_short_attendant_needs_approval_and_cannot_approve_it_themselves(): void
    {
        $id = $this->id();
        $closing = $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 1000000]);
        $closing['nozzles'] = [$closing['nozzles'][0], ['nozzle_id' => $this->nozzleB->id, 'reading' => '5000.000']];
        $closing['tenders'][] = ['attendant_id' => $this->attendant2->id, 'tenders' => [['kind' => 'cash', 'amount_minor' => '0']]];
        $this->uploadShifts([$this->fuelShiftBody($id, closing: $closing)])->assertOk();

        $settlement = $this->inTenant(fn () => Settlement::query()->where('attendant_id', $this->attendant->id)->firstOrFail());
        // 100 L = KES 18,050.00 expected, 10,000.00 counted: short 8,050.00.
        $this->assertSame(Settlement::PENDING, $settlement->status);
        $this->assertSame(-805000, $settlement->variance_minor);

        // Every actor: the attendant, the opener and the closer are refused; a station manager can approve.
        $this->inTenant(function () use ($settlement) {
            $type = new ShiftVarianceType;
            $this->assertFalse($type->mayAct($this->attendant, $settlement->id, 'approve'));
            $this->assertFalse($type->mayAct($this->supervisor, $settlement->id, 'approve'));
            $this->assertTrue($type->mayAct($this->manager, $settlement->id, 'approve'));
            $this->assertSame($this->supervisor->id, $type->requesterId($settlement->id));
        });

        $this->inTenant(function () use ($settlement) {
            $flow = app(WorkflowEngine::class)->current(ShiftVarianceType::KEY, $settlement->id);
            $this->assertNotNull($flow);
            $this->assertSame('running', $flow->status);
        });

        // The back office lists it for review and the manager reads it.
        $this->getJson('/api/v1/fuel/settlements?status=pending_approval', $this->headersFor($this->manager))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.variance_minor', '-805000');
        $this->getJson("/api/v1/fuel/settlements/{$settlement->id}", $this->headersFor($this->attendant))->assertForbidden();

        // Completing the flow settles it (the engine event is queued synchronously in tests).
        $this->inTenant(function () use ($settlement) {
            app(Settlements::class)->completed($settlement->id, 'approved', $this->manager->id);
            $this->assertSame(Settlement::APPROVED, Settlement::query()->findOrFail($settlement->id)->status);
        });
        $this->assertSame(1, $this->audited('fuel.settlement.approve'));
    }

    public function test_a_late_sale_reopens_a_decided_settlement(): void
    {
        $this->allowLitres('1000');
        $id = $this->id();
        $closing = $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 1805000]);
        $closing['tenders'][] = ['attendant_id' => $this->attendant2->id, 'tenders' => [['kind' => 'cash', 'amount_minor' => '0']]];
        $this->uploadShifts([$this->fuelShiftBody($id, closing: $closing)])->assertOk();
        $settlement = $this->inTenant(fn () => Settlement::query()->where('attendant_id', $this->attendant->id)->firstOrFail());
        // 100 L = 18,050.00 expected and 18,050.00 counted: accepted.
        $this->assertSame(Settlement::AUTO, $settlement->status);

        // The attendant also sold 10 L on M-Pesa that the till uploads late: collected is now above expected by that amount.
        $pos = $this->posShift();
        $body = $this->fuelSaleBody($id, $pos, '10.000', 1, ['payments' => [[
            'id' => $this->id(), 'payment_method_id' => $this->methods['mpesa']->id, 'currency' => 'KES', 'amount_minor' => '180500', 'amount_in_sale_minor' => '180500', 'rate' => null, 'status' => 'confirmed', 'provider_reference' => 'QWE1',
        ]]]);
        $this->upload([$body])->assertOk();

        $this->inTenant(function () use ($settlement) {
            $fresh = Settlement::query()->findOrFail($settlement->id);
            $this->assertSame(2, $fresh->version);
            $this->assertSame(Settlement::PENDING, $fresh->status);
            $this->assertSame(180500, $fresh->variance_minor);
            $this->assertNotNull($fresh->reopened_at);
        });
        // The shift's other settlement is reopened too: a flagged-sale reason or a changed figure touches every attendant of the shift.
        $this->assertGreaterThanOrEqual(1, $this->audited('fuel.settlement.reopen'));
        $reopened = $this->audited('fuel.settlement.reopen');

        // Uploading the same sale again changes nothing (idempotent).
        $this->upload([$body])->assertOk();
        $this->inTenant(fn () => $this->assertSame(1, FuelSale::query()->count()));
        $this->assertSame($reopened, $this->audited('fuel.settlement.reopen'));
    }

    public function test_readings_never_go_down_and_a_gap_is_flagged(): void
    {
        $this->uploadShifts([$this->fuelShiftBody($this->id(), closing: $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0]))])->assertOk();

        // Opening below the last reading is refused, and the nozzle is not taken by the refused shift.
        $low = $this->fuelShiftBody($this->id(), ['A' => '1099.999', 'B' => '5000.000']);
        $this->uploadShifts([$low])->assertStatus(422)->assertJsonPath('results.0.error.code', 'meter_decreased')->assertJsonPath('code', 'upload_rejected');
        $this->inTenant(fn () => $this->assertSame(1, Shift::query()->count()));

        // Above the last reading is accepted and the gap flagged.
        $this->uploadShifts([$this->fuelShiftBody($id = $this->id(), ['A' => '1150.000', 'B' => '5000.000'])])->assertOk();
        $this->inTenant(function () use ($id) {
            $row = Shift::query()->findOrFail($id)->shiftNozzles()->where('nozzle_id', $this->nozzleA->id)->first();
            $this->assertSame('50.000', $row->gap_litres);
            $this->assertSame('meter_gap', $row->flags[0]['code']);
        });
    }

    public function test_a_closing_reading_below_the_opening_is_refused_unless_a_reset_was_approved(): void
    {
        $id = $this->id();
        $base = $this->fuelShiftBody($id);
        $this->uploadShifts([$base])->assertOk();
        $closing = $this->fuelClosing(['A' => '20.000', 'B' => '5010.000'], ['A' => 0, 'B' => 0]);
        $lower = [...$base, 'closing' => $closing];
        $this->uploadShifts([$lower])->assertStatus(422)->assertJsonPath('results.0.error.code', 'meter_decreased');
        $this->inTenant(fn () => $this->assertSame(Shift::OPEN, Shift::query()->findOrFail($id)->status));

        // The supervisor asks for a reset from the till (1050 before, 0 after); the requester cannot decide it; the manager approves.
        $reset = ['id' => $this->id(), 'nozzle_id' => $this->nozzleA->id, 'shift_id' => $id, 'reading_before' => '1050.000', 'reading_after' => '0.000', 'reason' => 'Meter replaced',
            'requested_by_id' => $this->supervisor->id, 'requested_at' => now()->toIso8601String(), 'actor_proof' => $this->actorProof($this->supervisor->id)];
        $this->postJson('/api/v1/fuel/device/meter-resets', ['resets' => [$reset]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.reset_status', 'pending');
        $this->postJson("/api/v1/fuel/meter-resets/{$reset['id']}/approve", [], $this->headersFor($this->supervisor))->assertForbidden();
        $this->postJson("/api/v1/fuel/meter-resets/{$reset['id']}/approve", [], $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, $this->audited('fuel.meter_reset.approve'));

        // 50 L before the swap (1050 - 1000) plus 20 L after = 70 L.
        $this->uploadShifts([$lower])->assertOk()->assertJsonPath('results.0.state', 'closed');
        $this->inTenant(function () use ($id) {
            $row = Shift::query()->findOrFail($id)->shiftNozzles()->where('nozzle_id', $this->nozzleA->id)->first();
            $this->assertSame('70.000', $row->meter_litres);
            $this->assertSame(MeterReset::APPROVED, MeterReset::query()->first()->status);
        });
    }

    public function test_a_close_without_every_reading_or_tender_is_refused_and_the_shift_stays_open(): void
    {
        $id = $this->id();
        $base = $this->fuelShiftBody($id);
        $closing = $this->fuelClosing(['A' => '1100.000'], ['A' => 0]);
        $this->uploadShifts([[...$base, 'closing' => $closing]])->assertStatus(422)->assertJsonPath('results.0.error.code', 'close_incomplete');
        // The opening was in the same refused request, so nothing was kept; opened alone it stays open and the close is refused too.
        $this->uploadShifts([$base])->assertOk()->assertJsonPath('results.0.state', 'open');
        $this->uploadShifts([[...$base, 'closing' => $closing]])->assertStatus(422)->assertJsonPath('results.0.error.code', 'close_incomplete');
        $this->inTenant(fn () => $this->assertSame(Shift::OPEN, Shift::query()->findOrFail($id)->status));
    }

    public function test_uploads_are_idempotent_and_a_changed_resend_is_refused(): void
    {
        $body = $this->fuelShiftBody($this->id());
        $this->uploadShifts([$body])->assertOk();
        $this->uploadShifts([$body])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->inTenant(fn () => $this->assertSame(1, Shift::query()->count()));

        $changed = $body;
        $changed['opening']['nozzles'][0]['reading'] = '1001.000';
        $this->uploadShifts([$changed])->assertStatus(422)->assertJsonPath('results.0.error.code', 'payload_mismatch');
    }

    public function test_a_nozzle_cannot_be_on_two_open_shifts_and_an_unproved_actor_is_flagged(): void
    {
        $this->uploadShifts([$this->fuelShiftBody($this->id())])->assertOk();
        $this->uploadShifts([$this->fuelShiftBody($this->id())])->assertStatus(422)->assertJsonPath('results.0.error.code', 'nozzle_in_use')->assertJsonPath('results.0.error.retryable', true);

    }

    public function test_an_actor_the_till_cannot_prove_is_flagged_and_other_places_are_refused(): void
    {
        $body = $this->fuelShiftBody($this->id());
        $body['opening']['actor_proof'] = null;
        $this->uploadShifts([$body])->assertOk()->assertJsonPath('results.0.flags.0.code', 'actor_unverified');

        // A till at a location that is not a station gets nothing.
        [, $token] = $this->pairedTill($this->locationB, 'Till B');
        $this->uploadShifts([$this->fuelShiftBody($this->id())], $token)->assertStatus(422)->assertJsonPath('results.0.error.code', 'not_a_station');
    }

    public function test_sales_without_a_known_shift_are_stored_flagged_and_join_it_when_it_arrives(): void
    {
        $pos = $this->posShift();
        $fuelShift = $this->id();
        $this->upload([$this->fuelSaleBody($fuelShift, $pos, '4.000', 1)])->assertOk();
        $this->upload([$this->fuelSaleBody($fuelShift, $pos, '1.000', 2, ['extensions' => []])])->assertOk();
        $codes = $this->inTenant(fn () => FuelSale::query()->orderBy('litres')->get()->map(fn ($s) => array_column($s->flags, 'code'))->all());
        $this->assertContains('shift_unknown', $codes[1]);

        // The shift arrives later: the sale that named it joins it, with its nozzle and attendant.
        $this->uploadShifts([$this->fuelShiftBody($fuelShift)])->assertOk();
        $this->inTenant(function () use ($fuelShift) {
            $joined = FuelSale::query()->where('claimed_shift_id', $fuelShift)->first();
            $this->assertSame($fuelShift, $joined->shift_id);
            $this->assertSame($this->attendant->id, $joined->attendant_id);
            $this->assertNotContains('shift_unknown', array_column($joined->flags, 'code'));
        });
        $this->getJson('/api/v1/fuel/sales?flagged=1', $this->headersFor($this->supervisor))->assertOk();
    }

    public function test_a_user_of_another_tenant_named_by_the_till_is_a_clean_refusal_never_a_500(): void
    {
        $stranger = $this->otherTenant()['user']->id;
        $body = fn (callable $change) => (function () use ($change) {
            $shift = $this->fuelShiftBody($this->id());
            $change($shift);

            return $shift;
        })();

        // The opener, an attendant, and (on a shift of ours) the closer.
        $this->uploadShifts([$body(fn (&$s) => $s['opening']['opened_by_id'] = $stranger)])->assertStatus(422)->assertJsonPath('results.0.error.code', 'user_unknown');
        $this->uploadShifts([$body(fn (&$s) => $s['opening']['nozzles'][0]['attendant_id'] = $stranger)])->assertStatus(422)->assertJsonPath('results.0.error.code', 'user_unknown');
        $base = $this->fuelShiftBody($this->id());
        $this->uploadShifts([$base])->assertOk();
        $closing = $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0]);
        $closing['closed_by_id'] = $stranger;
        $this->uploadShifts([[...$base, 'closing' => $closing]])->assertStatus(422)->assertJsonPath('results.0.error.code', 'user_unknown');

        // A meter reset requester.
        $reset = ['id' => $this->id(), 'nozzle_id' => $this->nozzleB->id, 'reading_before' => '5000.000', 'reading_after' => '0.000', 'reason' => 'Meter replaced', 'requested_by_id' => $stranger, 'requested_at' => now()->toIso8601String()];
        $this->postJson('/api/v1/fuel/device/meter-resets', ['resets' => [$reset]], $this->tillHeaders())->assertStatus(422)->assertJsonPath('results.0.error.code', 'user_unknown');
    }
}
