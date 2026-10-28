<?php

namespace Modules\FuelStation\Tests;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Pin\TillSignIn;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Support\Str;
use Modules\FuelStation\Approvals\ShiftVarianceType;
use Modules\FuelStation\Approvals\TankVarianceType;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Reconciliation;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Models\Voucher;
use Modules\FuelStation\Models\VoucherRedemption;
use Modules\FuelStation\Tests\Concerns\BuildsFuel;
use Modules\FuelStation\Vouchers\Vouchers;
use Modules\POS\Models\Sale;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Security review fixes: meter gaps, pump tests, flagged sales, server prices, device-named actors, stale resets, vouchers,
// the POS hook, tank allowances, cancelled approvals (FUEL-11 to FUEL-28).
class HardeningTest extends TestCase
{
    use BuildsFuel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFuel();
    }

    /** @return array{0: array, 1: string} the opening body and the fuel shift id (opened, with the sales not yet closed) */
    private function opened(array $readings = ['A' => '1000.000', 'B' => '5000.000']): array
    {
        $body = $this->fuelShiftBody($this->id(), $readings);
        $this->uploadShifts([$body])->assertOk();

        return [$body, $body['id']];
    }

    private function settlementOf($user, ?string $shiftId = null): Settlement
    {
        return $this->inTenant(fn () => Settlement::query()->where('attendant_id', $user->id)->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))->orderByDesc('created_at')->firstOrFail());
    }

    // 1
    public function test_an_opening_reading_above_the_last_close_is_charged_to_the_shift_and_always_reviewed(): void
    {
        $this->allowLitres('100000', '10');
        $this->uploadShifts([$this->fuelShiftBody($this->id(), closing: $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 1805000, 'B' => 0]))])->assertOk();

        // 50 L went through the meter between the shifts. The next shift opens at 1150 and sells nothing: the gap is its unrecorded litres.
        $body = $this->fuelShiftBody($this->id(), ['A' => '1150.000', 'B' => '5000.000']);
        $this->uploadShifts([[...$body, 'closing' => $this->fuelClosing(['A' => '1150.000', 'B' => '5000.000'], ['A' => 902500, 'B' => 0])]])->assertOk();

        $a = $this->settlementOf($this->attendant, $body['id']);
        $this->assertSame(902500, $a->expected_minor);
        $this->assertSame(0, $a->variance_minor);
        $this->assertSame(Settlement::PENDING, $a->status, 'a meter gap is never auto accepted, even with the cash counted');
        $this->assertContains('meter_gap', array_column($a->flags, 'code'));
        $this->assertSame('50.000', $a->litres_variance);
    }

    // 2
    public function test_pump_tests_above_the_allowance_are_flagged_and_reviewed(): void
    {
        $this->allowLitres('100000', '10');
        $closing = $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 1606450, 'B' => 0]);
        $closing['nozzles'][0]['test_litres'] = '11.000';
        $this->uploadShifts([$this->fuelShiftBody($this->id(), closing: $closing)])->assertOk();

        $a = $this->settlementOf($this->attendant);
        $this->assertSame(0, $a->variance_minor);
        $this->assertSame(Settlement::PENDING, $a->status);
        $this->assertContains('test_above_tolerance', array_column($a->flags, 'code'));
        $this->assertSame(Settlement::AUTO, $this->settlementOf($this->attendant2)->status);
    }

    // 3
    public function test_an_expired_voucher_counts_for_nothing_collected(): void
    {
        $this->allowLitres('100000', '10');
        [$body, $shift] = $this->opened();
        $this->inTenant(fn () => Voucher::create(['company_id' => $this->acme->id, 'code' => 'OLD100', 'currency' => 'KES', 'value_minor' => 500000, 'balance_minor' => 500000, 'expires_on' => now()->subDays(2)->toDateString(), 'issued_by' => $this->owner->id]));
        $sale = $this->fuelSaleBody($shift, $this->posShift(), '10.000');
        $sale['payments'][0]['payment_method_id'] = $this->voucherMethod->id;
        $sale['payments'][0]['tender'] = ['code' => 'OLD100'];
        $this->upload([$sale])->assertOk();
        $this->uploadShifts([[...$body, 'closing' => $this->fuelClosing(['A' => '1010.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0])]])->assertOk();

        $this->inTenant(function () {
            $r = VoucherRedemption::query()->firstOrFail();
            $this->assertSame(0, (int) $r->applied_minor);
            $this->assertSame(500000, (int) Voucher::query()->firstOrFail()->balance_minor);
            $this->assertSame('voucher_expired', $r->flags[0]['code']);
        });
        $a = $this->settlementOf($this->attendant);
        $this->assertSame(-180500, $a->variance_minor, 'the voucher paid nothing');
        $this->assertSame(Settlement::PENDING, $a->status);
    }

    public function test_any_flagged_sale_sends_a_balanced_shift_to_approval(): void
    {
        $this->allowLitres('100000', '10');
        [$body, $shift] = $this->opened();
        $credit = $this->inTenant(fn () => PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'credit', 'name' => 'Account', 'currency' => 'KES', 'active' => true, 'position' => 7]));
        $sale = $this->fuelSaleBody($shift, $this->posShift(), '10.000');
        $sale['payments'][0]['payment_method_id'] = $credit->id;
        $this->upload([$sale])->assertOk();
        $this->uploadShifts([[...$body, 'closing' => $this->fuelClosing(['A' => '1010.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0])]])->assertOk();

        $a = $this->settlementOf($this->attendant);
        $this->assertSame(0, $a->variance_minor, 'credit sale recorded, nothing else to count');
        $this->assertContains('vehicle_required', array_column($this->inTenant(fn () => FuelSale::query()->firstOrFail()->flags), 'code'));
        $this->assertSame(Settlement::PENDING, $a->status);
        $this->assertContains('flagged_sales', array_column($a->flags, 'code'));
        // The other attendant's settlement is the shift's too.
        $this->assertSame(Settlement::PENDING, $this->settlementOf($this->attendant2)->status);
    }

    public function test_a_late_sale_never_reopens_a_settlement_as_auto_accepted(): void
    {
        $this->allowLitres('100000', '10');
        [$body, $shift] = $this->opened();
        $this->uploadShifts([[...$body, 'closing' => $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 1805000, 'B' => 0])]])->assertOk();
        $this->assertSame(Settlement::AUTO, $this->settlementOf($this->attendant)->status);

        // A cash sale of 10 L reaches the server late: the money is already inside the counted cash, so the variance is still 0 and
        // inside every tolerance, but the figures moved, so it goes back for approval.
        $this->upload([$this->fuelSaleBody($shift, $this->posShift(), '10.000')])->assertOk();
        $a = $this->settlementOf($this->attendant);
        $this->assertSame(0, $a->variance_minor);
        $this->assertSame(2, $a->version);
        $this->assertSame(Settlement::PENDING, $a->status);
        $this->inTenant(fn () => $this->assertSame('running', app(WorkflowEngine::class)->current(ShiftVarianceType::KEY, $a->id)->status));
    }

    // 4
    public function test_expected_value_uses_the_server_price_not_the_tills(): void
    {
        $this->allowLitres('100000', '10');
        [$body, $shift] = $this->opened();
        $sale = $this->fuelSaleBody($shift, $this->posShift(), '10.000');
        // The till sold at KES 170.00 a litre; the station's price is KES 180.50.
        $sale['lines'][0]['unit_price_minor'] = $sale['lines'][0]['list_price_minor'] = '17000';
        $sale['lines'][0]['total_minor'] = '170000';
        $sale['lines'][0]['tax_minor'] = '18889';
        $sale['totals'] = ['subtotal_minor' => '170000', 'discount_minor' => '0', 'tax_minor' => '18889', 'total_minor' => '170000'];
        $sale['payments'][0]['amount_minor'] = $sale['payments'][0]['amount_in_sale_minor'] = '170000';
        $this->upload([$sale])->assertOk();
        $this->uploadShifts([[...$body, 'closing' => $this->fuelClosing(['A' => '1010.000', 'B' => '5000.000'], ['A' => 170000, 'B' => 0])]])->assertOk();

        $a = $this->settlementOf($this->attendant);
        $this->assertSame(180500, $a->expected_minor);
        $this->assertSame(-10500, $a->variance_minor);
    }

    // 5
    public function test_when_the_till_could_not_prove_who_acted_everyone_signed_in_is_refused_approval(): void
    {
        $this->allowLitres('0');
        $unproved = $this->fuelShiftBody($this->id());
        $unproved['opening']['actor_proof'] = null;
        $closing = $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0]);
        $closing['actor_proof'] = null;
        $this->uploadShifts([[...$unproved, 'closing' => $closing]])->assertOk();
        $a = $this->settlementOf($this->attendant);
        $this->assertSame(Settlement::PENDING, $a->status);
        $row = $this->inTenant(fn () => ShiftNozzle::query()->where('attendant_id', $this->attendant->id)->firstOrFail());
        $this->assertContains('attendant_unverified', array_column($row->flags, 'code'));

        // The manager had signed in on that till during the shift: the till's record of who did what is a claim, so they cannot decide it.
        $this->inTenant(fn () => TillSignIn::create(['device_id' => $this->till->id, 'user_id' => $this->manager->id, 'session_id' => (string) Str::uuid7(), 'signed_in_at' => now()->subHours(2), 'verified_at' => now()]));
        $this->inTenant(function () use ($a) {
            $type = new ShiftVarianceType;

            foreach (['approve', 'move', 'cancel'] as $action) {
                $this->assertFalse($type->mayAct($this->manager, $a->id, $action), $action);
                $this->assertFalse($type->mayAct($this->attendant, $a->id, $action), $action);
            }

            $other = $this->userWith('station_manager', Scope::location($this->locationA->id));
            $this->assertTrue($type->mayAct($other, $a->id, 'approve'), 'someone who was never signed in on that till can');
        });
    }

    public function test_with_every_actor_proved_a_manager_who_signed_in_on_the_till_may_still_approve(): void
    {
        $this->allowLitres('0');
        $body = $this->fuelShiftBody($this->id());
        $body['opening']['nozzles'][0]['attendant_proof'] = $this->actorProof($this->attendant->id);
        $body['opening']['nozzles'][1]['attendant_proof'] = $this->actorProof($this->attendant2->id);
        $this->uploadShifts([[...$body, 'closing' => $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0])]])->assertOk();
        $a = $this->settlementOf($this->attendant);
        // Each attendant's own attestation (`attendant_proof`) clears the unverified flag on their nozzle.
        $this->inTenant(fn () => ShiftNozzle::query()->get()->each(fn ($row) => $this->assertNotContains('attendant_unverified', array_column($row->flags ?? [], 'code'))));
        $this->inTenant(fn () => TillSignIn::create(['device_id' => $this->till->id, 'user_id' => $this->manager->id, 'session_id' => (string) Str::uuid7(), 'signed_in_at' => now()->subHours(2), 'verified_at' => now()]));
        $this->inTenant(fn () => $this->assertTrue((new ShiftVarianceType)->mayAct($this->manager, $a->id, 'approve')));
    }

    // 6
    public function test_a_reset_is_refused_when_the_meter_moved_since_and_blocks_a_shift_opening(): void
    {
        $this->uploadShifts([$this->fuelShiftBody($this->id(), closing: $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0]))])->assertOk();
        $reset = $this->postJson('/api/v1/fuel/meter-resets', ['nozzle_id' => $this->nozzleA->id, 'reading_before' => '1100', 'reading_after' => '0', 'reason' => 'Meter replaced'], $this->headersFor($this->supervisor))
            ->assertCreated()->json('data');

        // While it waits, no shift opens on that meter.
        $this->uploadShifts([$this->fuelShiftBody($this->id(), ['A' => '1100.000', 'B' => '5000.000'])])->assertStatus(422)->assertJsonPath('results.0.error.code', 'reset_pending')->assertJsonPath('results.0.error.retryable', true);

        // The meter moved anyway (another reading was accepted): the request no longer describes it.
        $this->inTenant(fn () => Nozzle::query()->whereKey($this->nozzleA->id)->update(['last_reading_litres' => '1200.000']));
        $this->postJson("/api/v1/fuel/meter-resets/{$reset['id']}/approve", [], $this->headersFor($this->manager))->assertStatus(409)->assertJsonPath('code', 'reset_stale');
        $this->inTenant(fn () => $this->assertSame(MeterReset::PENDING, MeterReset::query()->findOrFail($reset['id'])->status));
    }

    public function test_a_reset_asked_from_an_unproved_till_cannot_be_decided_by_someone_signed_in_on_it(): void
    {
        $reset = ['id' => $this->id(), 'nozzle_id' => $this->nozzleA->id, 'reading_before' => '1000.000', 'reading_after' => '0.000', 'reason' => 'Meter replaced',
            'requested_by_id' => $this->supervisor->id, 'requested_at' => now()->subMinutes(10)->toIso8601String(), 'actor_proof' => null];
        $this->postJson('/api/v1/fuel/device/meter-resets', ['resets' => [$reset]], $this->tillHeaders())->assertOk();
        $this->inTenant(fn () => TillSignIn::create(['device_id' => $this->till->id, 'user_id' => $this->manager->id, 'session_id' => (string) Str::uuid7(), 'signed_in_at' => now()->subHour(), 'verified_at' => now()]));

        $this->postJson("/api/v1/fuel/meter-resets/{$reset['id']}/approve", [], $this->headersFor($this->manager))->assertForbidden()->assertJsonPath('code', 'own_reset');
        $other = $this->userWith('station_manager', Scope::location($this->locationA->id));
        $this->postJson("/api/v1/fuel/meter-resets/{$reset['id']}/approve", [], $this->headersFor($other))->assertOk();
    }

    // 7
    public function test_a_voucher_gives_back_only_what_it_paid_and_a_refund_does_too(): void
    {
        [$body, $shift] = $this->opened();
        $voucher = $this->inTenant(fn () => Voucher::create(['company_id' => $this->acme->id, 'code' => 'LOW1000', 'currency' => 'KES', 'value_minor' => 500000, 'balance_minor' => 1000, 'issued_by' => $this->owner->id]));
        $sale = $this->fuelSaleBody($shift, $this->posShift(), '10.000');
        $sale['payments'][0]['payment_method_id'] = $this->voucherMethod->id;
        $sale['payments'][0]['tender'] = ['code' => 'LOW1000'];
        $this->upload([$sale])->assertOk();

        $this->inTenant(function () use ($voucher, $sale) {
            $r = VoucherRedemption::query()->firstOrFail();
            $this->assertSame(1000, (int) $r->applied_minor, 'only the balance was applied');
            $this->assertSame(180500, (int) $r->amount_minor);
            $this->assertSame('voucher_overdrawn', $r->flags[0]['code']);
            $this->assertSame(0, (int) Voucher::query()->findOrFail($voucher->id)->balance_minor);

            // A refund of the whole payment gives back 1,000 at most, once per refund.
            $refund = ['id' => $this->id(), 'sale_id' => $sale['id'], 'company_id' => $this->acme->id, 'payments' => [['method_type' => 'fuel_voucher', 'tender' => ['code' => 'LOW1000'], 'amount_in_sale_minor' => '180500']]];
            app(Vouchers::class)->refund($refund);
            app(Vouchers::class)->refund($refund);
            $this->assertSame(1000, (int) Voucher::query()->findOrFail($voucher->id)->balance_minor);

            // A void afterwards has nothing left to give back.
            app(Vouchers::class)->reverse($sale['id'], null);
            $this->assertSame(1000, (int) Voucher::query()->findOrFail($voucher->id)->balance_minor);
        });
    }

    public function test_a_void_returns_the_applied_amount_and_an_expired_voucher_is_never_redeemed(): void
    {
        [$body, $shift] = $this->opened();
        $voucher = $this->inTenant(fn () => Voucher::create(['company_id' => $this->acme->id, 'code' => 'FUEL5', 'currency' => 'KES', 'value_minor' => 500000, 'balance_minor' => 500000, 'issued_by' => $this->owner->id]));
        $sale = $this->fuelSaleBody($shift, $this->posShift(), '10.000');
        $sale['payments'][0]['payment_method_id'] = $this->voucherMethod->id;
        $sale['payments'][0]['tender'] = ['code' => 'FUEL5'];
        $this->upload([$sale])->assertOk();
        $this->inTenant(function () use ($voucher, $sale) {
            $this->assertSame(500000 - 180500, (int) Voucher::query()->findOrFail($voucher->id)->balance_minor);
            app(Vouchers::class)->reverse($sale['id'], null);
            $this->assertSame(500000, (int) Voucher::query()->findOrFail($voucher->id)->balance_minor);
        });
    }

    // 8
    public function test_the_fuel_permission_rings_only_fuel_lines_and_an_empty_extension_grants_nothing(): void
    {
        [$body, $shift] = $this->opened();
        $pos = $this->posShift();
        $flags = fn (array $sale) => $this->inTenant(fn () => array_column(Sale::query()->findOrFail($sale['id'])->flags ?? [], 'code'));

        // Fuel only: the attendant holds no pos.sale.create and needs none.
        $fuel = $this->fuelSaleBody($shift, $pos, '5.000', 1);
        $this->upload([$fuel])->assertOk();
        $this->assertNotContains('cashier_not_permitted', $flags($fuel));

        // A shop line riding on a fuel sale is not covered.
        $mixed = $this->fuelSaleBody($shift, $pos, '5.000', 2);
        $mixed['lines'][] = $this->line();
        $mixed['totals'] = ['subtotal_minor' => '202500', 'discount_minor' => '0', 'tax_minor' => '25000', 'total_minor' => '202500'];
        $mixed['payments'][0]['amount_minor'] = $mixed['payments'][0]['amount_in_sale_minor'] = '202500';
        $mixed['totals']['subtotal_minor'] = (string) ((int) $mixed['lines'][0]['total_minor'] + 112500);
        $mixed['totals']['total_minor'] = $mixed['payments'][0]['amount_minor'] = $mixed['payments'][0]['amount_in_sale_minor'] = $mixed['totals']['subtotal_minor'];
        $mixed['totals']['tax_minor'] = (string) ((int) $mixed['lines'][0]['tax_minor'] + 12500);
        $this->upload([$mixed])->assertOk();
        $this->assertContains('cashier_not_permitted', $flags($mixed));

        // An empty fuel extension gives no rights at all.
        $empty = $this->fuelSaleBody($shift, $pos, '5.000', 3, ['extensions' => ['fuel' => []]]);
        $this->upload([$empty])->assertOk();
        $this->assertContains('cashier_not_permitted', $flags($empty));
    }

    // 9
    public function test_splitting_a_loss_over_several_dips_cannot_stay_under_the_allowance(): void
    {
        $this->inTenant(fn () => Tank::query()->whereKey($this->tank->id)->update(['tolerance_litres' => '5.000']));
        $dip = fn (string $cm, int $minutes) => $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => $cm, 'taken_at' => now()->subMinutes($minutes)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()->json('data.reconciliation');
        $dip('50', 60);
        $this->assertSame('accepted', $dip('49.97', 40)['status']);
        $second = $dip('49.94', 20);
        $this->assertSame('-3.000', $second['variance_litres']);
        $this->assertSame(Reconciliation::PENDING, $second['status'], '3 L alone is within 5 L, but 6 L in a day is not');
    }

    public function test_water_in_the_tank_is_not_fuel(): void
    {
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '50', 'water_cm' => '2', 'taken_at' => now()->subMinutes(5)->toIso8601String()], $this->headersFor($this->supervisor))
            ->assertCreated()->assertJsonPath('data.litres', '4800.000');
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '10', 'water_cm' => '11'], $this->headersFor($this->supervisor))->assertStatus(422)->assertJsonPath('code', 'water_above_dip');
    }

    public function test_litres_sold_while_offloading_come_from_the_sales_between_the_dips(): void
    {
        [$body, $shift] = $this->opened();
        $this->upload([$this->fuelSaleBody($shift, $this->posShift(), '20.000')])->assertOk();
        // Dip 50 -> 60 cm is 1,000 L in the tank; 20 L left through the nozzle meanwhile; a typed figure is ignored.
        $this->postJson('/api/v1/fuel/deliveries', [
            'tank_id' => $this->tank->id, 'note_number' => 'DN-S', 'note_litres' => '1020', 'before_dip_cm' => '50', 'after_dip_cm' => '60', 'sold_during_litres' => '500',
            'before_taken_at' => now()->subMinutes(30)->toIso8601String(), 'after_taken_at' => now()->toIso8601String(), 'unit_cost_minor' => 15000, 'currency' => 'KES',
        ], $this->headersFor($this->supervisor))->assertCreated()->assertJsonPath('data.sold_during_litres', '20.000')->assertJsonPath('data.received_litres', '1020.000')->assertJsonPath('data.shortage_litres', '0.000');
    }

    public function test_fuel_variance_goes_to_station_managers_and_the_branch_manager_of_that_station_but_not_the_attendant(): void
    {
        $branchHere = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $branchElsewhere = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $stationHere = $this->userWith('station_manager', Scope::location($this->locationA->id));

        $this->uploadShifts([$this->fuelShiftBody($this->id(), closing: $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0]))])->assertOk();
        $a = $this->settlementOf($this->attendant);

        $approvers = $this->inTenant(fn () => ApprovalAssignment::query()
            ->whereIn('request_id', ApprovalRequest::query()->where('document_id', $a->id)->select('id'))
            ->where('status', ApprovalAssignment::PENDING)->pluck('user_id')->all());

        $this->assertContains($branchHere->id, $approvers);
        $this->assertContains($stationHere->id, $approvers);
        $this->assertNotContains($branchElsewhere->id, $approvers);
        $this->assertNotContains($this->attendant->id, $approvers);

        // A branch manager who worked the shift still cannot approve it.
        $this->inTenant(fn () => $this->assertTrue((new ShiftVarianceType)->mayAct($branchHere, $a->id, 'approve')));
        $this->inTenant(fn () => $this->assertFalse((new ShiftVarianceType)->mayAct($this->attendant, $a->id, 'approve')));
    }

    // 10
    public function test_a_cancelled_approval_can_be_submitted_again_and_the_admin_can_decide_the_escalation(): void
    {
        $this->uploadShifts([$this->fuelShiftBody($this->id(), closing: $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 0, 'B' => 0]))])->assertOk();
        $a = $this->settlementOf($this->attendant);
        $this->assertSame(Settlement::PENDING, $a->status);

        $admin = $this->userWith('admin', Scope::company($this->acme->id));
        $this->inTenant(fn () => $this->assertTrue((new ShiftVarianceType)->mayAct($admin, $a->id, 'approve')));

        // Cancelling the flow (the engine's event) leaves the settlement waiting, not pending for ever.
        $this->inTenant(fn () => app(WorkflowEngine::class)->cancel(app(WorkflowEngine::class)->current(ShiftVarianceType::KEY, $a->id), $this->manager, 'Cancelled for a recount'));
        $this->assertSame(Settlement::CANCELLED, $this->settlementOf($this->attendant)->status);
        $this->postJson("/api/v1/fuel/settlements/{$a->id}/resubmit", [], $this->headersFor($this->attendant))->assertForbidden();
        $this->postJson("/api/v1/fuel/settlements/{$a->id}/resubmit", [], $this->headersFor($this->supervisor))->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->inTenant(fn () => $this->assertSame('running', app(WorkflowEngine::class)->current(ShiftVarianceType::KEY, $a->id)->status));
        $this->postJson("/api/v1/fuel/settlements/{$a->id}/resubmit", [], $this->headersFor($this->supervisor))->assertStatus(409)->assertJsonPath('code', 'not_cancelled');

        // The same for a tank variance.
        $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '50', 'taken_at' => now()->subHours(3)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated();
        $loss = $this->postJson('/api/v1/fuel/dips', ['tank_id' => $this->tank->id, 'dip_cm' => '49', 'taken_at' => now()->subHours(2)->toIso8601String()], $this->headersFor($this->supervisor))->assertCreated()->json('data.reconciliation');
        $this->inTenant(fn () => app(WorkflowEngine::class)->cancel(app(WorkflowEngine::class)->current(TankVarianceType::KEY, $loss['id']), $this->manager, 'Cancelled'));
        $this->getJson("/api/v1/fuel/reconciliations/{$loss['id']}", $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/fuel/reconciliations/{$loss['id']}/resubmit", [], $this->headersFor($this->supervisor))->assertOk()->assertJsonPath('data.status', 'pending_approval');
    }
}
