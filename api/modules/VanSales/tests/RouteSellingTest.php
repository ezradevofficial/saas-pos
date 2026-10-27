<?php

namespace Modules\VanSales\Tests;

use App\Core\Rbac\Scope;
use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Models\CreditEntry;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\StockLine;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// VAN-06, VAN-09, VAN-10, VAN-11, VAN-13, VAN-16, VAN-20, VAN-27: the rep's
// day on the van's device: runs and visits uploaded idempotently, sales
// (cash, M-Pesa, credit) linked to the run with their tenders, stock lines
// and credit entries, flagged and never refused, voids taking them back.
class RouteSellingTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpVanSales();
    }

    public function test_a_run_is_started_once_and_the_same_record_answers_the_same(): void
    {
        $record = $this->runRecord();

        $this->vsSync(['runs' => [$record]])->assertOk()->assertJsonPath('results.runs.0.status', 'stored');
        $this->vsSync(['runs' => [$record]])->assertOk()->assertJsonPath('results.runs.0.status', 'stored');

        $this->inTenant(function () use ($record) {
            $this->assertSame(1, Run::query()->count());
            $run = Run::findOrFail($record['id']);
            $this->assertSame([$this->van->id, $this->vanLocation->id, $this->rep->id, 'open'], [$run->van_id, $run->location_id, $run->rep_id, $run->status]);
        });
    }

    public function test_a_second_day_cannot_start_while_one_is_open(): void
    {
        $this->startRun();

        $this->vsSync(['runs' => [$this->runRecord()]])->assertStatus(422)->assertJsonPath('code', 'upload_rejected')
            ->assertJsonPath('results.runs.0.code', 'run_open');
    }

    public function test_a_person_without_the_right_cannot_start_a_day(): void
    {
        $stranger = $this->userWith('cashier', Scope::location($this->locationB->id));

        $this->vsSync(['runs' => [$this->runRecord(['user_id' => $stranger->id])]])->assertStatus(422)
            ->assertJsonPath('results.runs.0.code', 'not_permitted');
    }

    public function test_visits_are_last_writer_wins_and_keep_sold(): void
    {
        $run = $this->startRun();
        $visit = $this->visitRecord($run, $this->shop);
        $this->vsSync(['visits' => [$visit]])->assertOk()->assertJsonPath('results.visits.0.status', 'stored');

        $noSale = [...$visit, 'status' => 'no_sale', 'no_sale_reason' => 'closed', 'updated_at' => $this->at(100)];
        $this->vsSync(['visits' => [$noSale]])->assertOk();
        $stale = [...$visit, 'status' => 'skipped', 'updated_at' => $this->at(110)];
        $this->vsSync(['visits' => [$stale]])->assertOk()->assertJsonPath('results.visits.0.stale', true);

        $this->inTenant(fn () => $this->assertSame(['no_sale', 'closed'], [Visit::findOrFail($visit['id'])->status, Visit::findOrFail($visit['id'])->no_sale_reason]));

        $this->vsSync(['visits' => [[...$visit, 'status' => 'no_sale', 'no_sale_reason' => null, 'updated_at' => $this->at(90)]]])
            ->assertStatus(422)->assertJsonPath('results.visits.0.code', 'visit_invalid');
    }

    public function test_a_cash_sale_joins_the_run_with_its_stock_and_tender(): void
    {
        $run = $this->startRun();
        $visit = $this->visitRecord($run, $this->shop);
        $this->vsSync(['visits' => [$visit]])->assertOk();
        $sale = $this->vanSale($run, 1, $this->shop, null, '2', ['visit_id' => $visit['id']]);

        $this->sellOnVan($sale)->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->sellOnVan($sale)->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->inTenant(function () use ($run, $sale, $visit) {
            $link = VanSale::query()->where('sale_id', $sale['id'])->sole();
            $this->assertSame([$run, '112500', '0', 'completed', []], [$link->run_id, (string) $link->total_minor, (string) $link->credit_minor, $link->status, $link->flags]);
            $this->assertNull($link->review_status);
            $this->assertSame('sold', Visit::findOrFail($visit['id'])->status);
            $this->assertSame('2.000000', (string) StockLine::query()->where('run_id', $run)->where('kind', 'sold')->sole()->qty);
            $tender = Tender::query()->where('run_id', $run)->sole();
            $this->assertSame(['cash', 'KES', 112500], [$tender->kind, $tender->currency, (int) $tender->amount_minor]);
            $this->assertSame(0, CreditEntry::query()->count());
        });
    }

    public function test_change_leaves_the_expected_cash(): void
    {
        $run = $this->startRun();
        $sale = $this->vanSale($run, 1, null, [$this->pay('cash_kes', '120000')], '2', [], ['change' => ['currency' => 'KES', 'amount_minor' => '7500', 'rate' => null]]);

        $this->sellOnVan($sale)->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->inTenant(fn () => $this->assertSame(112500, (int) Tender::query()->where('run_id', $run)->sum('amount_minor')));
    }

    public function test_m_pesa_and_cash_are_kept_apart(): void
    {
        $run = $this->startRun();
        $sale = $this->vanSale($run, 1, null, [$this->pay('cash_kes', '12500'), $this->pay('mpesa', '100000')]);

        $this->sellOnVan($sale)->assertOk();

        $this->inTenant(fn () => $this->assertSame(['cash' => 12500, 'mobile_money' => 100000], Tender::query()->where('run_id', $run)->pluck('amount_minor', 'kind')->map(fn ($v) => (int) $v)->all()));
    }

    public function test_a_credit_sale_within_the_limit_adds_to_the_customers_balance_unflagged(): void
    {
        $run = $this->startRun();
        $sale = $this->vanSale($run, 1, $this->shop, [$this->pay('credit', '112500')], '2', ['credit' => ['limit_minor' => '1000000', 'balance_minor' => '0', 'currency' => 'KES', 'offline' => false]]);

        $this->sellOnVan($sale)->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->inTenant(function () use ($sale) {
            $link = VanSale::query()->where('sale_id', $sale['id'])->sole();
            $this->assertSame([[], null, 'online', '112500'], [$link->flags, $link->review_status, $link->credit_decision, (string) $link->credit_minor]);
            $this->assertSame(['KES' => 112500], app(Ledger::class)->balances($this->shop->id));
            $this->assertSame(0, Tender::query()->count());
        });
    }

    public function test_a_credit_sale_over_the_limit_is_stored_and_flagged_for_review(): void
    {
        $run = $this->startRun();
        $first = $this->vanSale($run, 1, $this->shop, [$this->pay('credit', '900000')], '16');
        // Total 900000 for 16 soaps (16 x 56250); balance is then 900000 of a 1,000,000 limit.
        $this->sellOnVan($first)->assertOk()->assertJsonPath('results.0.status', 'stored');
        $second = $this->vanSale($run, 2, $this->shop, [$this->pay('credit', '112500')], '2');

        $this->sellOnVan($second)->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->inTenant(function () use ($first, $second) {
            $this->assertSame([], VanSale::query()->where('sale_id', $first['id'])->sole()->flags);
            $link = VanSale::query()->where('sale_id', $second['id'])->sole();
            $this->assertSame(['over_limit'], $link->flags);
            $this->assertSame(['pending', '900000', '1000000'], [$link->review_status, (string) $link->balance_before_minor, (string) $link->limit_minor]);
            $this->assertSame(['KES' => 1012500], app(Ledger::class)->balances($this->shop->id));
        });
    }

    public function test_credit_without_a_limit_to_a_stranger_or_decided_offline_is_flagged(): void
    {
        $run = $this->startRun();
        $noLimit = $this->vanSale($run, 1, $this->kiosk, [$this->pay('credit', '112500')]);
        $noCustomer = $this->vanSale($run, 2, null, [$this->pay('credit', '112500')]);
        $offline = $this->vanSale($run, 3, $this->shop, [$this->pay('credit', '112500')], '2', ['credit' => ['limit_minor' => '500000', 'balance_minor' => '0', 'currency' => 'KES', 'offline' => true]], ['offline' => true]);

        $this->sellOnVan($noLimit, $noCustomer, $offline)->assertOk();

        $this->inTenant(function () use ($noLimit, $noCustomer, $offline) {
            $flags = fn (array $sale) => VanSale::query()->where('sale_id', $sale['id'])->sole()->flags;
            $this->assertSame(['no_credit_limit'], $flags($noLimit));
            $this->assertSame(['credit_without_customer'], $flags($noCustomer));
            $this->assertSame(['limit_changed', 'credit_offline'], $flags($offline));
        });
    }

    public function test_the_company_may_stop_flagging_offline_credit(): void
    {
        $this->api('PUT', "van-sales/companies/{$this->acme->id}/settings", ['flag_offline_credit' => false], $this->owner)->assertOk();
        $run = $this->startRun();
        $sale = $this->vanSale($run, 1, $this->shop, [$this->pay('credit', '112500')], '2', ['credit' => ['limit_minor' => '1000000', 'offline' => true]], ['offline' => true]);

        $this->sellOnVan($sale)->assertOk();

        $this->inTenant(fn () => $this->assertSame([], VanSale::query()->where('sale_id', $sale['id'])->sole()->flags));
    }

    public function test_a_sale_of_another_run_or_none_is_a_normal_sale_and_nothing_more(): void
    {
        $this->startRun();
        $ghost = $this->vanSale($this->id(), 1);
        $plain = $this->saleBody($this->vanShift, 2, ['cashier_id' => $this->rep->id, 'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice), 'receipt_number' => 'R-V01-000002']);

        $this->sellOnVan($ghost, $plain)->assertOk()->assertJsonPath('results.0.status', 'stored')->assertJsonPath('results.1.status', 'stored');

        $this->inTenant(fn () => $this->assertSame(0, VanSale::query()->count()));
    }

    public function test_a_void_takes_the_sale_back_from_the_run(): void
    {
        $run = $this->startRun();
        $sale = $this->vanSale($run, 1, $this->shop, [$this->pay('credit', '112500')]);
        $this->sellOnVan($sale)->assertOk();

        $this->voidSale($sale);

        $this->inTenant(function () use ($run, $sale) {
            $this->assertSame('voided', VanSale::query()->where('sale_id', $sale['id'])->sole()->status);
            $this->assertSame(0.0, (float) StockLine::query()->where('run_id', $run)->sum('qty'));
            $this->assertSame([], array_filter(app(Ledger::class)->balances($this->shop->id)));
            $this->assertSame(2, CreditEntry::query()->count());
        });
    }

    /** Voids a stored sale through the POS void upload, as the van's device would, with a manager's override. */
    private function voidSale(array $sale): void
    {
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'voided_by_id' => $this->rep->id, 'voided_at' => now()->toIso8601String(),
            'reason' => 'Wrong customer', 'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice), 'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders($this->vanToken))->assertOk()->assertJsonPath('results.0.void_status', 'applied');
    }
}
