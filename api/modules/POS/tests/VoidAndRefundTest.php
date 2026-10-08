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
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-05, RBAC-06, AUTH-08: voids and refunds are new records referencing
// the sale, need the permission or a manager's override (both users
// recorded), respect the refund limit, never refund more than was sold,
// and raise their events once.
class VoidAndRefundTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $shift;

    private User $cashier;

    private User $manager;

    private array $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
        // Three soaps at KES 562.50: KES 1,687.50, tax KES 187.50.
        $this->sale = $this->saleBody($this->shift, 1, ['cashier_id' => $this->cashier->id, 'lines' => [$this->line(['qty' => '3', 'tax_minor' => '18750', 'total_minor' => '168750'])]]);
        $this->upload([$this->sale])->assertOk()->assertJsonPath('results.0.flags', []);
    }

    private function void(array $overrides = [])
    {
        return $this->postJson('/api/v1/pos/voids', ['voids' => [array_replace([
            'id' => $this->id(), 'sale_id' => $this->sale['id'], 'voided_by_id' => $this->cashier->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Customer changed their mind', 'override' => null,
        ], $overrides)]], $this->tillHeaders());
    }

    private function refundBody(string $qty, string $total, int $seq = 1, array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(), 'sale_id' => $this->sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->cashier->id,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => $total,
            'lines' => [['id' => $this->id(), 'sale_line_id' => $this->sale['lines'][0]['id'], 'qty' => $qty]],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total]],
            'override' => ['manager_id' => $this->manager->id, 'proof' => 'pin-proof'],
        ], $overrides);
    }

    private function refund(array $body)
    {
        return $this->postJson('/api/v1/pos/refunds', ['refunds' => [$body]], $this->tillHeaders());
    }

    private function limit(string $template, string $key, string $value): void
    {
        $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get($template)->id, 'key' => $key, 'value' => $value]));
    }

    public function test_a_cashier_needs_a_manager_to_void_and_both_are_recorded(): void
    {
        Event::fake([SaleVoided::class]);

        $this->void()->assertUnprocessable()->assertJsonPath('results.0.error.code', 'override_required');

        $body = ['id' => $this->id(), 'override' => ['manager_id' => $this->manager->id, 'proof' => 'pin-proof']];
        $first = $this->void($body)->assertOk()->assertJsonPath('results.0.override_verified', false);
        $this->assertSame($first->json('results'), $this->void($body)->assertOk()->json('results'));

        $this->inTenant(function () use ($body) {
            $void = SaleVoid::findOrFail($body['id']);
            $this->assertSame([$this->cashier->id, $this->manager->id], [$void->voided_by, $void->approved_by]);
            $this->assertSame(Sale::VOIDED, Sale::findOrFail($this->sale['id'])->status);
            $this->assertSame(1, AuditEntry::where('action', 'pos.sale.void')->where('on_behalf_of_user_id', $this->manager->id)->count());
        });
        Event::assertDispatchedTimes(SaleVoided::class, 1);

        // A second void, and a refund of a voided sale, are refused.
        $this->void(['voided_by_id' => $this->manager->id])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'sale_already_voided');
        $this->refund($this->refundBody('1', '56250'))->assertUnprocessable()->assertJsonPath('results.0.error.code', 'sale_already_voided');
    }

    public function test_a_manager_who_lacks_the_permission_cannot_override(): void
    {
        $this->void(['override' => ['manager_id' => $this->cashier->id]])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'override_not_permitted');
        $this->void(['sale_id' => $this->id()])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'sale_unknown')->assertJsonPath('results.0.error.retryable', true);
    }

    public function test_refunds_respect_the_approvers_limit_in_the_base_currency(): void
    {
        Event::fake([SaleRefunded::class]);

        // The branch manager template has no refund limit: no rule means not allowed (RBAC-06).
        $this->refund($this->refundBody('1', '56250'))->assertUnprocessable()->assertJsonPath('results.0.error.code', 'override_limit_exceeded');

        // A KES 500.00 limit is below KES 562.50; KES 1,000.00 covers it.
        $this->limit('branch_manager', 'max_refund_amount', '500');
        $this->refund($this->refundBody('1', '56250'))->assertUnprocessable()->assertJsonPath('results.0.error.code', 'override_limit_exceeded');
        $this->inTenant(fn () => LimitRule::where('key', 'max_refund_amount')->update(['value' => '1000']));
        $body = $this->refundBody('1', '56250');
        $this->refund($body)->assertOk()->assertJsonPath('results.0.receipt_number', 'RF-L01-000001');

        $this->inTenant(function () use ($body) {
            $refund = Refund::findOrFail($body['id']);
            $this->assertSame([$this->cashier->id, $this->manager->id, '56250', '6250', '56250'], [$refund->cashier_id, $refund->approved_by, (string) $refund->total_minor, (string) $refund->tax_minor, (string) $refund->base_total_minor]);
            $this->assertSame('1.000000', SaleLine::findOrFail($this->sale['lines'][0]['id'])->refunded_qty);
            $this->assertSame(1, AuditEntry::where('action', 'pos.sale.refund')->count());
        });
        Event::assertDispatchedTimes(SaleRefunded::class, 1);

        // A refunded sale can no longer be voided.
        $this->void(['voided_by_id' => $this->manager->id])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'sale_has_refunds');
    }

    public function test_a_line_is_never_refunded_beyond_what_was_sold_and_its_parts_add_up(): void
    {
        $this->limit('cashier', 'max_refund_amount', '100000');
        $this->inTenant(fn () => $this->roles->get('cashier')->givePermissionTo('pos.sale.refund'));
        $own = ['override' => null];

        // 3 sold: 1, then 1.5, then 0.5 (the last part takes what is left: 168750 - 56250 - 84375).
        $this->refund($this->refundBody('1', '56250', 1, $own))->assertOk();
        $this->refund($this->refundBody('1.5', '84375', 2, $own))->assertOk();
        $this->refund($this->refundBody('1', '56250', 3, $own))->assertUnprocessable()->assertJsonPath('results.0.error.code', 'refund_qty_exceeded');
        $this->refund($this->refundBody('0.5', '28125', 3, $own))->assertOk();

        $this->inTenant(function () {
            $this->assertSame('168750', (string) RefundLine::sum('total_minor'));
            $this->assertSame('18750', (string) RefundLine::sum('tax_minor'));
        });

        // A total the lines don't give is refused.
        $this->refund($this->refundBody('0.1', '1', 4, $own))->assertUnprocessable();
    }

    public function test_refund_money_must_add_up_and_numbers_come_from_the_refund_range(): void
    {
        $this->limit('branch_manager', 'max_refund_amount', '100000');

        $short = $this->refundBody('1', '56250');
        $short['payments'][0]['amount_minor'] = $short['payments'][0]['amount_in_sale_minor'] = '50000';
        $this->refund($short)->assertUnprocessable()->assertJsonPath('results.0.error.code', 'refund_payments_mismatch');

        $this->refund($this->refundBody('1', '56250', 1, ['receipt_number' => 'R-L01-000001']))->assertUnprocessable()->assertJsonPath('results.0.error.code', 'receipt_number_mismatch');
        $this->refund($this->refundBody('1', '56250', 999))->assertUnprocessable()->assertJsonPath('results.0.error.code', 'receipt_range_unknown');
    }
}
