<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\ShiftBalance;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// H2, H3, AUTH-07, AUTH-08, POS-05: money out the server can't prove is
// held: nothing changes and nothing is raised until someone holding the
// permission at the location approves it in the back office (applied,
// events, audit naming them) or rejects it.
class HeldReviewTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $shift;

    private User $cashier;

    private User $manager;

    private array $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPos();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'key' => 'max_refund_amount', 'value' => '100000']));
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
        $this->sale = $this->saleBody($this->shift, 1, ['lines' => [$this->line(['qty' => '3', 'tax_minor' => '18750', 'total_minor' => '168750'])]]);
        $this->upload([$this->sale])->assertOk();
    }

    private function heldVoid(): string
    {
        $id = $this->id();
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $id, 'sale_id' => $this->sale['id'], 'voided_by_id' => $this->cashier->id, 'voided_at' => now()->toIso8601String(),
            'reason' => 'Wrong item', 'override' => $this->override($this->manager->id, proven: false),
        ]]], $this->tillHeaders())->assertOk()
            ->assertJsonPath('results.0.void_status', 'held')
            ->assertJsonPath('results.0.flags.0.code', 'override_unverified');

        return $id;
    }

    private function heldRefund(string $qty = '1', string $total = '56250', int $seq = 1): string
    {
        $id = $this->id();
        // The owner may refund, but nothing proves it was them at the till (H3).
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $id, 'sale_id' => $this->sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => $total,
            'lines' => [['id' => $this->id(), 'sale_line_id' => $this->sale['lines'][0]['id'], 'qty' => $qty]],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total]],
        ]]], $this->tillHeaders())->assertOk()
            ->assertJsonPath('results.0.refund_status', 'held')
            ->assertJsonPath('results.0.flags.0.code', 'actor_unverified');

        return $id;
    }

    public function test_a_held_void_changes_nothing_until_approved_by_a_manager(): void
    {
        Event::fake([SaleVoided::class]);
        $void = $this->heldVoid();

        $this->inTenant(fn () => $this->assertSame(Sale::COMPLETED, Sale::findOrFail($this->sale['id'])->status));
        Event::assertNotDispatched(SaleVoided::class);

        $held = $this->getJson('/api/v1/pos/held', $this->headersFor($this->manager))->assertOk()->json('data');
        $this->assertSame([['void', $void]], array_map(fn ($r) => [$r['kind'], $r['id']], $held));
        // Phase 4 Task 6: the back office's names for the ids.
        $this->assertSame(['id' => $this->cashier->id, 'name' => $this->cashier->name], $held[0]['by_user']);
        $this->assertSame($this->manager->id, $held[0]['approver']['id']);
        $this->assertSame('R-L01-000001', $held[0]['sale_receipt_number']);
        $this->assertSame('Till 1', $held[0]['device']['name']);
        $this->assertSame('Outlet A', $held[0]['location']['name']);
        $this->assertNotNull($held[0]['occurred_at']);
        $this->getJson('/api/v1/pos/held?kind=refund', $this->headersFor($this->manager))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pos/held?flag=override_unverified', $this->headersFor($this->manager))->assertOk()->assertJsonCount(1, 'data');

        // A cashier reaches the location but may not void; another branch's manager does not reach it.
        $this->postJson("/api/v1/pos/voids/{$void}/approve", [], $this->headersFor($this->cashier))->assertForbidden();
        $other = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->postJson("/api/v1/pos/voids/{$void}/approve", [], $this->headersFor($other))->assertNotFound();

        $this->postJson("/api/v1/pos/voids/{$void}/approve", [], $this->headersFor($this->manager))->assertOk()
            ->assertJsonPath('data.status', 'applied')->assertJsonPath('data.decided_by', $this->manager->id);
        $this->postJson("/api/v1/pos/voids/{$void}/approve", [], $this->headersFor($this->manager))->assertUnprocessable()->assertJsonPath('code', 'not_held');

        $this->inTenant(function () {
            $this->assertSame(Sale::VOIDED, Sale::findOrFail($this->sale['id'])->status);
            $this->assertSame(1, AuditEntry::where('action', 'pos.sale.void')->where('on_behalf_of_user_id', $this->manager->id)->count());
        });
        Event::assertDispatchedTimes(SaleVoided::class, 1);
    }

    public function test_a_held_refund_reserves_nothing_in_the_drawer_and_can_be_rejected_or_approved(): void
    {
        Event::fake([SaleRefunded::class]);
        $first = $this->heldRefund('1', '56250', 1);
        // Held refunds count against the quantity: 2 more units at most.
        $this->heldRefund('2', '112500', 2);

        $this->inTenant(fn () => $this->assertSame('0.000000', SaleLine::findOrFail($this->sale['lines'][0]['id'])->refunded_qty));
        Event::assertNotDispatched(SaleRefunded::class);

        $this->postJson("/api/v1/pos/refunds/{$first}/reject", [], $this->headersFor($this->manager))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/pos/refunds/{$first}/reject", ['reason' => 'No receipt shown'], $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.status', 'rejected');

        // The rejected unit is free again; the second refund is approved within the manager's limit.
        $second = $this->inTenant(fn () => Refund::where('receipt_seq', 2)->value('id'));
        $this->postJson("/api/v1/pos/refunds/{$second}/approve", [], $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.status', 'applied');

        $this->inTenant(function () {
            $this->assertSame('2.000000', SaleLine::findOrFail($this->sale['lines'][0]['id'])->refunded_qty);
            $this->assertSame(1, AuditEntry::where('action', 'pos.sale.refund_reject')->count());
        });
        Event::assertDispatchedTimes(SaleRefunded::class, 1);
    }

    public function test_a_held_pay_out_counts_in_the_drawer_only_once_approved(): void
    {
        $id = $this->id();
        $this->postJson('/api/v1/pos/cash-movements', ['movements' => [[
            'id' => $id, 'shift_id' => $this->shift, 'user_id' => $this->owner->id, 'kind' => 'pay_out', 'currency' => 'KES',
            'amount_minor' => '10000', 'reason' => 'Taxi', 'occurred_at' => now()->toIso8601String(),
        ]]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.movement_status', 'held');

        $close = fn () => $this->postJson('/api/v1/pos/shifts', ['shifts' => [[...$this->shiftBody(['id' => $this->shift]), 'closing' => [
            'closed_by_id' => $this->owner->id, 'closed_at' => now()->toIso8601String(), 'counted' => [['currency' => 'KES', 'amount_minor' => '668750']],
        ]]]], $this->tillHeaders());
        $close()->assertOk();
        $detail = $this->getJson("/api/v1/pos/shifts/{$this->shift}", $this->headersFor($this->manager))->assertOk()->json('data');
        $this->assertSame(['held', 'Taxi', $this->owner->name], [$detail['cash_movements'][0]['status'], $detail['cash_movements'][0]['reason'], $detail['cash_movements'][0]['user']['name']]);
        $this->assertSame(0, $detail['received_after_close']);
        $expected = fn () => $this->inTenant(fn () => (string) ShiftBalance::where('shift_id', $this->shift)->sole()->expected_minor);
        $this->assertSame('668750', $expected());

        // Approved after the close: the closed shift is recounted (H4).
        $this->postJson("/api/v1/pos/cash-movements/{$id}/approve", [], $this->headersFor($this->manager))->assertOk();
        $this->assertSame('658750', $expected());
    }
}
