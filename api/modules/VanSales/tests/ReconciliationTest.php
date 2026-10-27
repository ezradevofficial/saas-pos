<?php

namespace Modules\VanSales\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Reconciliation\Equation;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// VAN-18 to VAN-23, WF-01, APR-07: load = sold + returned + damaged +
// variance, cash and M-Pesa expected against counted, a variance decided by
// a flow that the rep cannot approve, posting on approval, a recount after
// a rejection.
class ReconciliationTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    private string $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpVanSales();
        $this->run = $this->startRun();
        $this->loadVan('10', $this->run);
    }

    /** Sells 6 soaps: 337,500 in cash (4 soaps) and M-Pesa (2 soaps), or all cash. */
    private function sellSix(): void
    {
        $this->sellOnVan(
            $this->vanSale($this->run, 1, null, [$this->pay('cash_kes', '225000')], '4'),
            $this->vanSale($this->run, 2, null, [$this->pay('mpesa', '112500')], '2'),
        )->assertOk();
    }

    private function submitCount(array $lines, array $tenders, array $overrides = [])
    {
        return $this->vsSync(['counts' => [$this->countRecord($this->run, $lines, $tenders, $overrides)]]);
    }

    private function decide(string $countId, string $action, User $by)
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $countId)->sole());

        return $this->postJson("/api/v1/approvals/{$approval->id}/{$action}", $action === 'reject' ? ['comment' => 'Recount'] : [], $this->headersFor($by));
    }

    public function test_the_equation_is_load_minus_sold_returned_and_damaged(): void
    {
        $this->assertTrue(Equation::variance('10', '6', '3', '0')->isEqualTo('1'));
        $this->assertTrue(Equation::variance('10', '6', '4', '0')->isZero());
        $this->assertTrue(Equation::variance('10', '6', '5', '0')->isNegative());
        $this->assertTrue(BigDecimal::of(Equation::variance('10.5', '6.25', '3', '1.25'))->isEqualTo('0'));
        $this->assertSame(-7500, Equation::tenderVariance(337500, 330000));
    }

    public function test_a_day_that_adds_up_closes_at_once(): void
    {
        $this->sellSix();

        $this->submitCount(
            [['item_id' => $this->soap->id, 'returned_qty' => '4', 'damaged_qty' => '0']],
            [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000'], ['kind' => 'mobile_money', 'currency' => 'KES', 'counted_minor' => '112500']],
        )->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'balanced');

        $this->inTenant(function () {
            $this->assertSame('closed', Run::findOrFail($this->run)->status);
            $this->assertSame(0, ApprovalRequest::query()->count());
        });
    }

    public function test_a_variance_waits_for_a_decision_the_rep_cannot_give(): void
    {
        $this->sellSix();
        // The rep is also a supervisor of the branch, which still does not let them decide their own count.
        $this->inTenant(fn () => $this->assign($this->rep, $this->roles->get('route_supervisor'), Scope::branch($this->branchA->id)));

        $response = $this->submitCount(
            [['item_id' => $this->soap->id, 'returned_qty' => '3', 'damaged_qty' => '0']],
            [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '217500'], ['kind' => 'mobile_money', 'currency' => 'KES', 'counted_minor' => '112500']],
        )->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'pending')->assertJsonPath('results.counts.0.reconciliation.stock_variance_lines', 1);
        $id = $response->json('results.counts.0.id');

        $this->inTenant(function () use ($id) {
            $count = Count::findOrFail($id);
            $this->assertSame(['pending', 1, '7500'], [$count->status, $count->stock_variance_lines, (string) $count->cash_variance_base_minor]);
            $this->assertSame('counting', Run::findOrFail($this->run)->status);
        });

        $this->decide($id, 'approve', $this->rep)->assertForbidden();
        $this->inTenant(fn () => $this->assertSame('pending', Count::findOrFail($id)->status));

        $this->api('GET', "van-sales/reconciliations/{$id}", [], $this->supervisor)->assertOk()
            ->assertJsonPath('data.lines.0.variance_qty', '1')
            ->assertJsonPath('data.lines.0.load_qty', '10')
            ->assertJsonPath('data.lines.0.sold_qty', '6')
            ->assertJsonPath('data.tenders.0.kind', 'cash')
            ->assertJsonPath('data.tenders.0.variance_minor', '-7500');
    }

    public function test_approval_writes_off_the_shortage_and_the_damage_and_closes_the_run(): void
    {
        Event::fake([StockWrittenOff::class]);
        $this->sellSix();
        $id = $this->submitCount(
            [['item_id' => $this->soap->id, 'returned_qty' => '2', 'damaged_qty' => '1']],
            [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000'], ['kind' => 'mobile_money', 'currency' => 'KES', 'counted_minor' => '112500']],
        )->assertOk()->json('results.counts.0.id');

        $this->decide($id, 'approve', $this->supervisor)->assertOk();

        $this->inTenant(function () use ($id) {
            $count = Count::findOrFail($id);
            $this->assertSame(['approved', $this->supervisor->id], [$count->status, $count->decided_by]);
            $this->assertSame('closed', Run::findOrFail($this->run)->status);
        });
        // load 10 - sold 6 - returned 2 - damaged 1 = shortage 1: 1 damaged + 1 short written off.
        Event::assertDispatched(StockWrittenOff::class, fn (StockWrittenOff $e) => $e->writeOffId === $id && $e->source === 'van_sales.write_off'
            && $e->locationId === $this->vanLocation->id && $e->lines[0]['item_id'] === $this->soap->id && $e->lines[0]['qty'] === '2');
    }

    public function test_a_rejected_count_can_be_counted_again(): void
    {
        $this->sellSix();
        $first = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '1', 'damaged_qty' => '0']], [
            ['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000'], ['kind' => 'mobile_money', 'currency' => 'KES', 'counted_minor' => '112500'],
        ]);
        $this->vsSync(['counts' => [$first]])->assertOk();
        // Waiting for a decision: another count is refused.
        $this->vsSync(['counts' => [$this->countRecord($this->run)]])->assertStatus(422)->assertJsonPath('results.counts.0.code', 'count_open');

        $this->decide($first['id'], 'reject', $this->supervisor)->assertOk();
        $this->inTenant(fn () => $this->assertSame('rejected', Count::findOrFail($first['id'])->status));

        $second = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '4', 'damaged_qty' => '0']], [
            ['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000'], ['kind' => 'mobile_money', 'currency' => 'KES', 'counted_minor' => '112500'],
        ]);
        $this->vsSync(['counts' => [$second]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'balanced');
        $this->inTenant(fn () => $this->assertSame([2, 'closed'], [Count::findOrFail($second['id'])->attempt, Run::findOrFail($this->run)->status]));
    }

    public function test_the_same_count_again_answers_the_same_and_other_content_is_refused(): void
    {
        $this->sellSix();
        $record = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '4', 'damaged_qty' => '0']], [
            ['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000'], ['kind' => 'mobile_money', 'currency' => 'KES', 'counted_minor' => '112500'],
        ]);

        $this->vsSync(['counts' => [$record]])->assertOk();
        $this->vsSync(['counts' => [$record]])->assertOk()->assertJsonPath('results.counts.0.status', 'stored');
        $this->vsSync(['counts' => [[...$record, 'note' => 'Changed']]])->assertStatus(422)->assertJsonPath('results.counts.0.code', 'payload_mismatch');
        $this->inTenant(fn () => $this->assertSame(1, Count::query()->count()));
    }

    public function test_credit_never_counts_as_cash_and_a_voided_collection_leaves_expected_cash(): void
    {
        $this->sellOnVan($this->vanSale($this->run, 1, $this->shop, [$this->pay('credit', '112500')]))->assertOk();

        $this->submitCount([['item_id' => $this->soap->id, 'returned_qty' => '8', 'damaged_qty' => '0']], [])
            ->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'balanced')
            ->assertJsonPath('results.counts.0.reconciliation.tenders', []);
    }
}
