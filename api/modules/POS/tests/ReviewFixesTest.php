<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Numbering\NumberFormat;
use App\Core\Rbac\Scope;
use Modules\POS\Models\NumberRange;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Review fixes on the till's uploads: H1 rates on money out, M1 range
// matching, M3 flagged review, M4 list price missing, and the lows
// (payload_mismatch on same-id resends, cumulative refund allocation,
// range audit and `next` capped to the device's own ranges).
class ReviewFixesTest extends TestCase
{
    use BuildsExchangeRates, BuildsPos, RefreshTenantDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    public function test_change_given_at_a_rate_the_server_does_not_hold_is_flagged(): void
    {
        // KES sale, USD 10.00 tendered at the server's 129.5, change in USD at a till rate of 140.
        $this->rate('USD', 'KES', '129.5', at: '-1 day');
        $usd = fn (string $rate) => ['rate' => $rate, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop'];
        $body = $this->saleBody($this->shift, 1, [
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => '1000', 'amount_in_sale_minor' => '129500', 'rate' => $usd('129.5')]],
            'change' => ['currency' => 'USD', 'amount_minor' => '100', 'rate' => $usd('140')],
        ]);

        $this->upload([$body])->assertOk()->assertJsonPath('results.0.flags.0.code', 'change_rate_differs');
    }

    public function test_refunds_in_another_currency_pay_out_at_the_sales_own_rate_or_are_held(): void
    {
        // The sale was paid in USD at 129.5; the server's rate has moved to 140 since.
        $this->rate('USD', 'KES', '129.5', at: '-1 day');
        $tender = ['rate' => '129.5', 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop'];
        $sale = $this->saleBody($this->shift, 1, [
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => '869', 'amount_in_sale_minor' => '112535', 'rate' => $tender]],
            'change' => ['currency' => 'KES', 'amount_minor' => '0'],
        ]);
        $this->upload([$sale])->assertOk();
        $this->rate('USD', 'KES', '140', at: '-1 minute');

        $refund = fn (string $usdMinor, string $inSale, int $seq) => ['refunds' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id, 'actor_proof' => 'attested',
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(), 'reason' => 'Damaged',
            'total_minor' => '56250', 'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '1']],
            // Whatever rate the till claims is ignored: the sale's 129.5 is used.
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => $usdMinor, 'amount_in_sale_minor' => $inSale, 'rate' => ['rate' => '140', 'base' => 'USD', 'quote' => 'KES']]],
        ]]];

        // The till's amounts don't even add up to the refund: refused.
        $this->postJson('/api/v1/pos/refunds', $refund('434', '60760', 1), $this->tillHeaders())->assertUnprocessable()->assertJsonPath('results.0.error.code', 'refund_payments_mismatch');
        // USD 4.02 claimed as KES 562.50 (a 140 rate): the sale's 129.5 gives KES 520.59: held, at the sale's rate.
        $this->postJson('/api/v1/pos/refunds', $refund('402', '56250', 2), $this->tillHeaders())->assertOk()
            ->assertJsonPath('results.0.refund_status', 'held')->assertJsonPath('results.0.flags.0.code', 'refund_rate_differs');
        // At the sale's rate but short of the refund (USD 4.34 = KES 562.03): refused.
        $this->postJson('/api/v1/pos/refunds', $refund('434', '56203', 3), $this->tillHeaders())->assertUnprocessable()->assertJsonPath('results.0.error.code', 'refund_payments_mismatch');

        $this->inTenant(fn () => $this->assertSame('129.50000000', RefundPayment::sole()->fx->rate));
    }

    public function test_the_range_that_rendered_the_number_is_chosen_across_periods(): void
    {
        // A yearly format: 2026 and 2027 ranges both hold number 1.
        $this->inTenant(fn () => NumberFormat::create(['document_type' => 'pos.receipt', 'company_id' => $this->acme->id, 'pattern' => 'R-{YY}-{0001}', 'reset' => 'yearly']));
        $this->travelTo(now()->setDate(2026, 12, 31)->setTime(10, 0));
        $old = $this->ranges()->assertOk()->json('data');
        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(10, 0));
        $new = $this->ranges()->assertOk()->json('data');
        $this->assertSame([1, 1], [collect($old)->firstWhere('period', '2026')['from'], $new[0]['from']]);
        $shift = $this->shift;

        // A sale made on 31 December (offline) numbered from the 2026 range, uploaded in 2027.
        $late = $this->saleBody($shift, 1, ['receipt_number' => 'R-26-0001', 'sold_at' => '2026-12-31T15:00:00+03:00']);
        $this->upload([$late])->assertOk();
        $this->upload([$this->saleBody($shift, 1, ['receipt_number' => 'R-27-0001', 'number_range_id' => $new[0]['id']])])->assertOk();
        $this->inTenant(fn () => $this->assertSame(['R-26-0001', 'R-27-0001'], Sale::orderBy('receipt_number')->pluck('receipt_number')->all()));
    }

    public function test_flagged_sales_can_be_filtered_and_acknowledged(): void
    {
        $this->upload([$this->saleBody($this->shift, 1)])->assertOk();
        $flagged = $this->saleBody($this->shift, 2, ['actor_proof' => null]);
        $this->upload([$flagged])->assertOk();

        $owner = $this->headersFor();
        $this->getJson('/api/v1/pos/sales?flagged=1', $owner)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $flagged['id']);
        $this->getJson('/api/v1/pos/sales?flagged=0', $owner)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pos/sales?flag=actor_unverified&reviewed=0', $owner)->assertOk()->assertJsonCount(1, 'data');

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->postJson("/api/v1/pos/sales/{$flagged['id']}/review", [], $this->headersFor($cashier))->assertForbidden();
        $this->postJson("/api/v1/pos/sales/{$flagged['id']}/review", ['note' => 'Cashier signed in by PIN'], $owner)->assertOk()
            ->assertJsonPath('data.reviewed_by', $this->owner->id);
        $this->getJson('/api/v1/pos/sales?flagged=1&reviewed=0', $owner)->assertOk()->assertJsonCount(0, 'data');
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'pos.sale.review')->count()));

        $clean = $this->inTenant(fn () => Sale::where('receipt_seq', 1)->value('id'));
        $this->postJson("/api/v1/pos/sales/{$clean}/review", [], $owner)->assertUnprocessable()->assertJsonPath('code', 'not_flagged');
    }

    public function test_a_line_on_a_price_list_without_its_list_price_is_flagged(): void
    {
        $this->upload([$this->saleBody($this->shift, 1, ['lines' => [$this->line(['list_price_minor' => null])]])])->assertOk()
            ->assertJsonPath('results.0.flags.0.code', 'list_price_missing');
    }

    public function test_a_resend_with_other_content_under_the_same_id_is_refused(): void
    {
        $body = $this->saleBody($this->shift, 1);
        $this->upload([$body])->assertOk();

        $this->upload([[...$body, 'offline' => true]])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'payload_mismatch');
        $this->inTenant(fn () => $this->assertFalse(Sale::sole()->offline));
    }

    public function test_range_allocation_and_retirement_are_audited_and_next_is_capped_to_the_devices_ranges(): void
    {
        config(['pos.ranges.size' => 10, 'pos.ranges.threshold' => 3]);
        [, $other] = $this->pairedTill($this->locationA, 'Till 2');
        $this->ranges(token: $other)->assertOk();

        // A `next` outside the device's ranges says nothing: no range is marked spent.
        $this->ranges(next: 999999)->assertOk()->assertJsonPath('data.0.next', 1);

        $this->postJson("/api/v1/devices/{$this->till->id}/unpair", [], $this->headersFor())->assertOk();
        $this->inTenant(function () {
            $this->assertGreaterThanOrEqual(3, AuditEntry::where('action', 'pos.number_range.allocate')->count());
            $this->assertSame(NumberRange::where('device_id', $this->till->id)->count(), AuditEntry::where('action', 'pos.number_range.retire')->count());
        });
    }
}
