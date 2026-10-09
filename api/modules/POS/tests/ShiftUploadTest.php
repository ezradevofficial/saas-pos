<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\POS\Events\ShiftClosed;
use Modules\POS\Events\ShiftOpened;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Shift;
use Modules\POS\Models\ShiftBalance;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-04: shifts opened with a float and closed with the cash counted
// (idempotent by id), one open shift per till, permissions to open, close
// one's own and close another's, cash pay-ins and pay-outs, and the
// expected cash and variance per currency.
class ShiftUploadTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
    }

    private function shifts(array $shifts, ?string $token = null)
    {
        return $this->postJson('/api/v1/pos/shifts', ['shifts' => $shifts], $this->tillHeaders($token));
    }

    private function closing(?string $by = null, array $counted = [['currency' => 'KES', 'amount_minor' => '500000']]): array
    {
        return ['closed_by_id' => $by ?? $this->owner->id, 'closed_at' => now()->toIso8601String(), 'counted' => $counted, 'note' => 'End of day'];
    }

    public function test_a_shift_opens_once_and_a_second_open_shift_waits(): void
    {
        Event::fake([ShiftOpened::class, ShiftClosed::class]);
        $body = $this->shiftBody();

        $first = $this->shifts([$body])->assertOk()->assertJsonPath('results.0.shift_status', 'open');
        $this->assertSame($first->json('results'), $this->shifts([$body])->assertOk()->json('results'));
        Event::assertDispatchedTimes(ShiftOpened::class, 1);

        $this->shifts([$this->shiftBody()])->assertUnprocessable()
            ->assertJsonPath('results.0.error.code', 'shift_already_open')
            ->assertJsonPath('results.0.error.retryable', true);
        // A shift sent already closed (opened and closed offline) never takes the open slot.
        $this->shifts([[...$this->shiftBody(), 'closing' => $this->closing()]])->assertOk()->assertJsonPath('results.0.shift_status', 'closed');

        $this->inTenant(function () use ($body) {
            $shift = Shift::findOrFail($body['id']);
            $this->assertSame([$this->locationA->id, $this->till->id, $this->owner->id], [$shift->location_id, $shift->device_id, $shift->opened_by]);
            $this->assertSame('500000', (string) ShiftBalance::where('shift_id', $shift->id)->sole()->opening_minor);
            $this->assertSame(2, AuditEntry::where('action', 'pos.shift.open')->count());
        });
    }

    public function test_closing_computes_expected_cash_and_variance_per_currency(): void
    {
        Event::fake([ShiftClosed::class]);
        $shift = $this->openShift();

        // Two cash sales of KES 1,125.00, an M-Pesa sale (not cash), a voided cash sale,
        // a KES 200.00 pay-out, and a KES 562.50 cash refund.
        $this->upload([$this->saleBody($shift, 1)])->assertOk();
        $mpesa = $this->saleBody($shift, 2);
        $mpesa['payments'][0]['payment_method_id'] = $this->methods['mpesa']->id;
        $this->upload([$mpesa])->assertOk();
        $voided = $this->saleBody($shift, 3);
        $this->upload([$voided])->assertOk();
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'actor_proof' => $this->actorProof($this->owner->id), 'sale_id' => $voided['id'], 'voided_by_id' => $this->owner->id, 'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong item',
        ]]], $this->tillHeaders())->assertOk();
        $this->postJson('/api/v1/pos/cash-movements', ['movements' => [[
            'id' => $this->id(), 'actor_proof' => $this->actorProof($this->owner->id), 'shift_id' => $shift, 'user_id' => $this->owner->id, 'kind' => 'pay_out', 'currency' => 'KES',
            'amount_minor' => '20000', 'reason' => 'Cleaning supplies', 'occurred_at' => now()->toIso8601String(),
        ]]], $this->tillHeaders())->assertOk();
        $sold = $this->saleBody($shift, 4);
        $this->upload([$sold])->assertOk();
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $this->id(), 'actor_proof' => $this->actorProof($this->owner->id), 'sale_id' => $sold['id'], 'shift_id' => $shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => 1, 'receipt_number' => 'RF-L01-000001', 'refunded_at' => now()->toIso8601String(), 'reason' => 'Damaged',
            'total_minor' => '56250', 'lines' => [['id' => $this->id(), 'sale_line_id' => $sold['lines'][0]['id'], 'qty' => '1']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => '56250', 'amount_in_sale_minor' => '56250']],
        ]]], $this->tillHeaders())->assertOk();

        $this->shifts([[...$this->shiftBody(['id' => $shift]), 'closing' => $this->closing(counted: [
            ['currency' => 'KES', 'amount_minor' => '770000'],
            ['currency' => 'USD', 'amount_minor' => '0'],
        ])]])->assertOk()->assertJsonPath('results.0.shift_status', 'closed');

        $this->inTenant(function () use ($shift) {
            // 5,000.00 + 1,125.00 + 1,125.00 - 200.00 - 562.50 = 6,487.50 expected; 7,700.00 counted.
            $kes = ShiftBalance::where('shift_id', $shift)->where('currency', 'KES')->sole();
            $this->assertSame(['500000', '648750', '770000', '121250'], [(string) $kes->opening_minor, (string) $kes->expected_minor, (string) $kes->counted_minor, (string) $kes->variance_minor]);
            $usd = ShiftBalance::where('shift_id', $shift)->where('currency', 'USD')->sole();
            $this->assertSame(['0', '0', '0'], [(string) $usd->opening_minor, (string) $usd->expected_minor, (string) $usd->variance_minor]);
            $this->assertSame(Shift::CLOSED, Shift::findOrFail($shift)->status);
            $this->assertSame(1, AuditEntry::where('action', 'pos.shift.close')->count());
        });
        Event::assertDispatchedTimes(ShiftClosed::class, 1);

        // Closing again changes nothing; a sale arriving late is kept and flagged.
        $this->shifts([[...$this->shiftBody(['id' => $shift]), 'closing' => $this->closing(counted: [])]])->assertOk();
        $this->inTenant(fn () => $this->assertSame('770000', (string) ShiftBalance::where('shift_id', $shift)->where('currency', 'KES')->sole()->counted_minor));
        // H4: it lands on the closed shift: flagged, and the cash-up recounted (expected + KES 1,125.00).
        $this->upload([$this->saleBody($shift, 5)])->assertOk()
            ->assertJsonPath('results.0.flags.0.code', 'received_after_close');
        $this->inTenant(function () use ($shift) {
            $kes = ShiftBalance::where('shift_id', $shift)->where('currency', 'KES')->sole();
            $this->assertSame(['761250', '770000', '8750'], [(string) $kes->expected_minor, (string) $kes->counted_minor, (string) $kes->variance_minor]);
        });
        // The back office says the cash-up was recounted for a late record.
        $this->getJson("/api/v1/pos/shifts/{$shift}", $this->headersFor())->assertOk()->assertJsonPath('data.received_after_close', 1);
    }

    public function test_one_batch_closes_a_shift_and_opens_the_next_in_the_same_currency(): void
    {
        // NFR-04: after a day offline the till sends yesterday's close and today's open together.
        $yesterday = $this->shiftBody();
        $this->shifts([$yesterday])->assertOk();
        $today = $this->shiftBody();

        $this->shifts([[...$yesterday, 'closing' => $this->closing()], $today])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'closed')
            ->assertJsonPath('results.1.shift_status', 'open');

        // Within one shift a currency is counted once.
        $twice = [['currency' => 'KES', 'amount_minor' => '100'], ['currency' => 'KES', 'amount_minor' => '200']];
        $this->shifts([$this->shiftBody(['opening_float' => $twice])])->assertUnprocessable()->assertJsonValidationErrors('shifts.0.opening_float');
        $this->shifts([[...$today, 'closing' => $this->closing(counted: $twice)]])->assertUnprocessable()->assertJsonValidationErrors('shifts.0.closing.counted');
    }

    public function test_one_upload_may_open_and_close_a_shift_and_missing_permissions_are_flagged_not_refused(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $other = $this->userWith('cashier', Scope::location($this->locationA->id));
        $stranger = $this->inTenant(fn () => $this->colleague($this->owner));

        // Opened and closed by the same cashier in one upload.
        $this->shifts([[...$this->shiftBody(['opened_by_id' => $cashier->id]), 'closing' => $this->closing($cashier->id)]])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'closed');

        // Someone without pos.shift.open: the shift is kept (the till used it) and flagged for review.
        $strangers = $this->shiftBody(['opened_by_id' => $stranger->id]);
        $this->shifts([$strangers])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'open')
            ->assertJsonPath('results.0.flags', [['code' => 'opener_not_permitted', 'detail' => ['permission' => 'pos.shift.open']]]);
        $this->shifts([[...$strangers, 'closing' => $this->closing($stranger->id)]])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'closed')
            ->assertJsonPath('results.0.flags.1', ['code' => 'closer_not_permitted', 'detail' => ['permission' => 'pos.shift.close']]);

        // Another cashier (no pos.shift.manage) closes it: closed, flagged; a branch manager's close is not.
        $open = $this->shiftBody(['opened_by_id' => $cashier->id]);
        $this->shifts([$open])->assertOk()->assertJsonPath('results.0.flags', []);
        $this->shifts([[...$open, 'closing' => $this->closing($other->id)]])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'closed')
            ->assertJsonPath('results.0.flags', [['code' => 'closer_not_permitted', 'detail' => ['permission' => 'pos.shift.manage']]]);
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $managed = $this->shiftBody(['opened_by_id' => $cashier->id]);
        $this->shifts([[...$managed, 'closing' => $this->closing($manager->id)]])->assertOk()->assertJsonPath('results.0.flags', []);

        $this->inTenant(function () use ($open, $managed) {
            $this->assertSame([['code' => 'closer_not_permitted', 'detail' => ['permission' => 'pos.shift.manage']]], Shift::findOrFail($open['id'])->flags);
            $this->assertNull(Shift::findOrFail($managed['id'])->flags);
        });
    }

    public function test_cash_movements_need_permission_or_an_override_and_an_open_shift(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $shift = $this->openShift(openedBy: $cashier->id);
        $movement = fn (array $overrides = []) => array_replace([
            'id' => $this->id(), 'shift_id' => $shift, 'user_id' => $cashier->id, 'kind' => 'pay_in', 'currency' => 'KES',
            'amount_minor' => '100000', 'reason' => 'Float top-up', 'occurred_at' => now()->toIso8601String(), 'override' => null,
        ], $overrides);
        $post = fn (array $body) => $this->postJson('/api/v1/pos/cash-movements', ['movements' => [$body]], $this->tillHeaders());

        $post($movement())->assertUnprocessable()->assertJsonPath('results.0.error.code', 'override_required');
        $approved = $movement(['override' => $this->override($manager->id)]);
        $post($approved)->assertOk();
        $this->assertSame('stored', $post($approved)->assertOk()->json('results.0.status'));

        $this->inTenant(function () use ($approved, $manager) {
            $this->assertSame($manager->id, CashMovement::findOrFail($approved['id'])->approved_by);
            $this->assertSame(1, AuditEntry::where('action', 'pos.cash.pay_in')->count());
        });

        // H2: a pay-out nobody can prove is held: it doesn't count in the drawer.
        $held = $movement(['kind' => 'pay_out', 'override' => $this->override($manager->id, proven: false)]);
        $post($held)->assertOk()->assertJsonPath('results.0.movement_status', 'held');

        $this->shifts([[...$this->shiftBody(['id' => $shift, 'opened_by_id' => $cashier->id]), 'closing' => $this->closing($cashier->id, [['currency' => 'KES', 'amount_minor' => '600000']])]])->assertOk();
        $this->inTenant(fn () => $this->assertSame('600000', (string) ShiftBalance::where('shift_id', $shift)->sole()->expected_minor));

        // H4: made after the close: refused; made before it but received late: kept, flagged, recounted.
        $post($movement(['user_id' => $manager->id, 'override' => null, 'actor_proof' => $this->actorProof($manager->id), 'occurred_at' => now()->addMinute()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'shift_closed');
        $post($movement(['user_id' => $manager->id, 'override' => null, 'actor_proof' => $this->actorProof($manager->id), 'kind' => 'pay_out', 'amount_minor' => '50000', 'occurred_at' => now()->subMinutes(10)->toIso8601String()]))
            ->assertOk()->assertJsonPath('results.0.flags.0.code', 'received_after_close');
        $this->inTenant(function () use ($shift) {
            $kes = ShiftBalance::where('shift_id', $shift)->sole();
            $this->assertSame(['550000', '600000', '50000'], [(string) $kes->expected_minor, (string) $kes->counted_minor, (string) $kes->variance_minor]);
            $this->assertSame(1, AuditEntry::where('action', 'pos.shift.recount')->count());
        });
    }
}
