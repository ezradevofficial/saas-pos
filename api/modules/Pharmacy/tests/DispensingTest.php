<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Pharmacy\Events\DispenseFlagged;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Recalls\Recalls;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Sales\Dispensing;
use Modules\Pharmacy\Sales\HandlePosEvents;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\Qty;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleSnapshot;
use Modules\POS\Models\Sale;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-07, PHA-08, PHA-16, PHA-17, PHA-18, PHA-26, PHA-27: what a POS sale does
// to lots once it is stored: the till's batches or first-expiry-first-out, the
// server's re-check (expired, quarantined, recalled), the prescription and
// pharmacist rules, the controlled-drug register, voids and refunds.
class DispensingTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $cashier;

    private User $pharmacist;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPharmacy();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    /** @return list<Dispense> */
    private function dispenses(): array
    {
        return $this->inTenant(fn () => Dispense::query()->orderBy('created_at')->orderBy('id')->get()->all());
    }

    /** A sale rung up by the plain cashier (no pharmacist right). */
    private function sellAsCashier(array $lines, array $pharmacy = [], array $over = [], int $seq = 1): array
    {
        return $this->sellDrugs($this->shift, $seq, $lines, $pharmacy, ['cashier_id' => $this->cashier->id, ...$over]);
    }

    public function test_a_sale_takes_the_lots_the_till_chose_and_else_the_first_expiry(): void
    {
        $soon = $this->lot($this->para, 'SOON', 100, '10');
        $late = $this->lot($this->para, 'LATE', 300, '10');

        // The till chose the later lot: kept, and recorded as not first-expiry-first-out.
        $first = $this->sellAsCashier([$this->drugLine($this->para, '3', ['id' => $lineId = $this->id()])], ['lines' => [['line_id' => $lineId, 'lots' => [['lot_id' => $late->id, 'qty' => '3']]]]]);
        $this->assertSame(['10', '7'], [$this->lotQty($soon), $this->lotQty($late)]);
        $movement = $this->inTenant(fn () => LotMovement::query()->where('kind', 'sale')->sole());
        $this->assertSame([$first['id'], 'pos.sale', ['not_fefo']], [$movement->sale_id, $movement->source_type, $movement->flags]);
        $this->assertSame($first['receipt_number'], $movement->reference);

        // The till named nothing: the server takes the earliest expiry, and says so.
        $this->sellAsCashier([$this->drugLine($this->para, '4')], [], [], 2);
        $this->assertSame(['6', '7'], [$this->lotQty($soon), $this->lotQty($late)]);
        $this->assertContains('batch_not_chosen', $this->inTenant(fn () => LotMovement::query()->where('lot_id', $soon->id)->where('kind', 'sale')->sole()->flags));

        // Plain retail sales leave nothing to review.
        $this->assertSame([], $this->dispenses());

        // An item with no profile is not Pharmacy's: nothing moves.
        $this->sellAsCashier([$this->drugLine($this->plain, '1')], [], [], 3);
        $this->assertSame(2, $this->inTenant(fn () => LotMovement::query()->where('kind', 'sale')->count()));
    }

    public function test_a_resent_event_changes_nothing(): void
    {
        $lot = $this->lot($this->para, 'ONE', 100, '10');
        $sale = $this->sellAsCashier([$this->drugLine($this->para, '2')]);
        $this->assertSame('8', $this->lotQty($lot));

        $snapshot = $this->inTenant(fn () => SaleSnapshot::sale(Sale::query()->findOrFail($sale['id'])));
        $this->inTenant(fn () => app(HandlePosEvents::class)->completed(new SaleCompleted($this->owner->tenant_id, $sale['id'], $this->acme->id, $this->locationA->id, $snapshot)));

        $this->assertSame('8', $this->lotQty($lot));
        $this->assertSame(1, $this->inTenant(fn () => LotMovement::query()->where('kind', 'sale')->count()));
    }

    public function test_a_sale_of_an_expired_quarantined_or_recalled_lot_is_kept_and_flagged_for_review(): void
    {
        Event::fake([DispenseFlagged::class]);
        $old = $this->lot($this->para, 'OLD', -3, '5');
        $held = $this->lot($this->para, 'HELD', 100, '5');
        $this->inTenant(fn () => app(Lots::class)->quarantine($held, 'Damaged', null));

        $a = $this->sellAsCashier([$this->drugLine($this->para, '1', ['id' => $l1 = $this->id()])], ['lines' => [['line_id' => $l1, 'lots' => [['lot_id' => $old->id, 'qty' => '1']]]]]);
        $this->sellAsCashier([$this->drugLine($this->para, '1', ['id' => $l2 = $this->id()])], ['lines' => [['line_id' => $l2, 'lots' => [['lot_id' => $held->id, 'qty' => '1']]]]], [], 2);

        // Money was taken: both sales are stored (the device wins), the stock moved and both are listed.
        $this->assertSame(['4', '4'], [$this->lotQty($old), $this->lotQty($held)]);
        [$one, $two] = $this->dispenses();
        $this->assertEqualsCanonicalizing([['lot_expired'], ['lot_quarantined']], [$one->flags, $two->flags]);
        $this->assertSame(['open', 'open'], [$one->review_status, $two->review_status]);
        $this->assertSame($a['receipt_number'], $this->inTenant(fn () => Dispense::query()->where('sale_id', $a['id'])->sole()->receipt_number));
        Event::assertDispatchedTimes(DispenseFlagged::class, 2);

        // PHA-22: a recalled lot, sold on a stale till.
        $recalled = $this->lot($this->para, 'BAD', 100, '5');
        $this->inTenant(fn () => app(Recalls::class)->issue($this->para->id, 'BAD', 'Contamination', null, $this->owner));
        $third = $this->sellAsCashier([$this->drugLine($this->para, '1', ['id' => $l3 = $this->id()])], ['lines' => [['line_id' => $l3, 'lots' => [['lot_id' => $recalled->id, 'qty' => '1']]]]], [], 3);
        $this->assertSame(['lot_recalled'], $this->inTenant(fn () => Dispense::query()->where('sale_id', $third['id'])->sole()->flags));
    }

    public function test_a_prescription_drug_needs_a_prescription_and_a_pharmacist(): void
    {
        $lot = $this->lot($this->amox, 'RX', 200, '20');

        // Nothing offered: kept, and flagged twice.
        $this->sellAsCashier([$this->drugLine($this->amox, '1')]);
        [$bare] = $this->dispenses();
        $this->assertEqualsCanonicalizing(['prescription_missing', 'pharmacist_missing', 'batch_not_chosen'], $bare->flags);
        $this->assertSame(['prescription_only', 'none', 'open'], [$bare->class, $bare->approval_mode, $bare->review_status]);

        // A prescription from the till and a pharmacist's signed override: nothing to review.
        $prescription = $this->prescriptionBody();
        $sale = $this->sellAsCashier([$this->drugLine($this->amox, '2', ['id' => $line = $this->id()])], [
            'lines' => [['line_id' => $line, 'lots' => [['lot_id' => $lot->id, 'qty' => '2']]]],
            'prescription' => $prescription,
            'approval' => $this->override($this->pharmacist->id),
        ], [], 2);
        $ok = $this->inTenant(fn () => Dispense::query()->where('sale_id', $sale['id'])->sole());
        $this->assertSame([[], 'override', $this->pharmacist->id, $prescription['id'], 'none'], [$ok->flags, $ok->approval_mode, $ok->pharmacist_id, $ok->prescription_id, $ok->review_status]);

        $stored = $this->inTenant(fn () => Prescription::query()->findOrFail($prescription['id']));
        $this->assertSame(['Dr Test Prescriber', 'LIC-0001', 'Test Clinic', 'Pat Patient', 'till', $sale['id']], [$stored->prescriber_name, $stored->prescriber_licence, $stored->facility, $stored->patient_name, $stored->source, $stored->sale_id]);

        // A pharmacist at the till needs nobody else.
        $own = $this->sellDrugs($this->shift, 3, [$this->drugLine($this->amox, '1', ['id' => $l3 = $this->id()])], [
            'lines' => [['line_id' => $l3, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]]], 'prescription' => $this->prescriptionBody(),
        ], ['cashier_id' => $this->pharmacist->id]);
        $self = $this->inTenant(fn () => Dispense::query()->where('sale_id', $own['id'])->sole());
        $this->assertSame(['self', $this->pharmacist->id, []], [$self->approval_mode, $self->pharmacist_id, $self->flags]);
    }

    public function test_an_approval_the_server_cannot_prove_and_an_old_prescription_are_flagged(): void
    {
        $this->settings(['prescription_valid_days' => 30]);
        $lot = $this->lot($this->amox, 'RX', 200, '20');
        $sale = $this->sellAsCashier([$this->drugLine($this->amox, '1', ['id' => $line = $this->id()])], [
            'lines' => [['line_id' => $line, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]]],
            'prescription' => $this->prescriptionBody(['prescribed_on' => $this->inDays(-45)]),
            'approval' => $this->override($this->pharmacist->id, proven: false),
        ]);

        $dispense = $this->inTenant(fn () => Dispense::query()->where('sale_id', $sale['id'])->sole());
        $this->assertEqualsCanonicalizing(['prescription_stale', 'pharmacist_unverified'], $dispense->flags);
        $this->assertSame('open', $dispense->review_status);
    }

    public function test_one_prescription_serves_the_whole_sale_and_is_made_once(): void
    {
        $lot = $this->lot($this->amox, 'RX', 200, '20');
        $vet = $this->lot($this->vet, 'VT', 200, '20');
        $prescription = $this->prescriptionBody();
        $sale = $this->sellAsCashier([
            $this->drugLine($this->amox, '1', ['id' => $a = $this->id()]),
            $this->drugLine($this->vet, '1', ['id' => $b = $this->id()]),
        ], [
            'lines' => [['line_id' => $a, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]], ['line_id' => $b, 'lots' => [['lot_id' => $vet->id, 'qty' => '1']]]],
            'prescription' => $prescription, 'approval' => $this->override($this->pharmacist->id),
        ]);

        $this->assertSame(2, $this->inTenant(fn () => Dispense::query()->where('prescription_id', $prescription['id'])->count()));
        $this->assertSame(1, $this->inTenant(fn () => Prescription::count()));
        $this->assertNotNull($sale['id']);
    }

    public function test_a_restricted_agrochemical_needs_a_named_buyer(): void
    {
        $lot = $this->lot($this->pesticide, 'AG', 200, '9');
        $this->sellAsCashier([$this->drugLine($this->pesticide, '1')], ['approval' => $this->override($this->pharmacist->id)]);
        [$walkIn] = $this->dispenses();
        $this->assertContains('buyer_missing', $walkIn->flags);
        $this->assertSame('agrochemical', $walkIn->class);

        $customer = $this->customer();
        $this->sellAsCashier([$this->drugLine($this->pesticide, '1')], ['approval' => $this->override($this->pharmacist->id)], ['customer_id' => $customer->id], 2);
        $named = $this->inTenant(fn () => Dispense::query()->where('customer_id', $customer->id)->sole());
        $this->assertNotContains('buyer_missing', $named->flags);
        $this->assertSame('7', $this->lotQty($lot));
    }

    public function test_a_controlled_drug_is_written_to_the_register_with_its_balance(): void
    {
        $lot = $this->lot($this->morphine, 'C-1', 200, '10');
        $this->inTenant(fn () => app(ControlledRegister::class)->append($this->acme->id, $this->locationA->id, $this->morphine->id, RegisterEntry::RECEIPT, Qty::of('10'), 'test.receipt', (string) Str::uuid7(), null, $lot));

        $prescription = $this->prescriptionBody();
        $sale = $this->sellAsCashier([$this->drugLine($this->morphine, '3', ['id' => $line = $this->id()])], [
            'lines' => [['line_id' => $line, 'lots' => [['lot_id' => $lot->id, 'qty' => '3']]]],
            'prescription' => $prescription, 'approval' => $this->override($this->pharmacist->id),
        ]);

        $entries = $this->inTenant(fn () => RegisterEntry::query()->orderBy('entry_no')->get());
        $this->assertSame([1, 2], $entries->pluck('entry_no')->all());
        $dispense = $entries->last();
        $this->assertSame(['dispense', '-3.000000', '7.000000', 'C-1', 'Pat Patient', 'Dr Test Prescriber', 'LIC-0001', $this->pharmacist->id, $sale['id']],
            [$dispense->kind, (string) $dispense->qty, (string) $dispense->balance, $dispense->batch_number, $dispense->patient_name, $dispense->prescriber_name, $dispense->prescriber_licence, $dispense->pharmacist_id, $dispense->source_id]);

        $this->assertSame('7', $this->lotQty($lot));
    }

    public function test_a_void_brings_the_lots_back_and_one_that_arrives_first_leaves_stock_alone(): void
    {
        $lot = $this->lot($this->para, 'V-1', 200, '10');
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $sale = $this->sellAsCashier([$this->drugLine($this->para, '4')]);
        $this->assertSame('6', $this->lotQty($lot));

        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $void = $this->id(), 'sale_id' => $sale['id'], 'voided_by_id' => $this->owner->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Mistake', 'override' => $this->override($manager->id),
        ]]], $this->tillHeaders())->assertOk();
        $this->assertSame('10', $this->lotQty($lot));
        $this->assertSame(['receipt', 'sale', 'sale_void'], $this->inTenant(fn () => LotMovement::query()->pluck('kind')->sort()->values()->all()));

        // The void handled again moves nothing more.
        $this->inTenant(fn () => app(Dispensing::class)->voided($sale['id'], $void));
        $this->assertSame('10', $this->lotQty($lot));
    }

    public function test_a_refund_brings_back_only_what_was_refunded_into_the_lots_it_came_from(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $first = $this->lot($this->para, 'R-1', 100, '2');
        $second = $this->lot($this->para, 'R-2', 200, '10');
        $sale = $this->sellAsCashier([$this->drugLine($this->para, '5', ['id' => $line = $this->id()])]);
        // Two from the first lot, three from the second.
        $this->assertSame(['0', '7'], [$this->lotQty($first), $this->lotQty($second)]);

        $stored = $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $refund = $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => 1, 'receipt_number' => 'RF-L01-000001', 'refunded_at' => now()->toIso8601String(), 'reason' => 'Wrong item', 'total_minor' => (string) (56250 * 3),
            'lines' => [['id' => $this->id(), 'sale_line_id' => $line, 'qty' => '3']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => (string) (56250 * 3), 'amount_in_sale_minor' => (string) (56250 * 3)]],
            'override' => $this->override($manager->id),
        ]]], $this->tillHeaders())->assertOk();

        if ($stored->json('results.0.refund_status') === 'held') {
            $this->postJson("/api/v1/pos/refunds/{$refund}/approve", ['note' => 'Checked'], $this->headersFor())->assertOk();
        }

        // First sold first: the first lot's two units, then one of the second's.
        $this->assertSame(['2', '8'], [$this->lotQty($first), $this->lotQty($second)]);
        $this->assertSame(2, $this->inTenant(fn () => LotMovement::query()->where('kind', 'sale_return')->where('source_id', $refund)->count()));
    }
}
