<?php

namespace Modules\Accounting\Tests;

use App\Core\Currency\Money;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Dimensions\CostCentre;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** ACC-16 to ACC-18: statements, the trial balance property, GL drill-down, budgets and consolidation. */
class ReportsTest extends TestCase
{
    use BuildsAccounting, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    /** Random balanced journals over postable non-control accounts, posted straight through the poster. */
    private function randomJournals(int $count, int $seed): void
    {
        mt_srand($seed);
        $this->inTenant(function () use ($count) {
            $accounts = Account::query()->where('company_id', $this->acme->id)->where('postable', true)->where('is_control', false)->pluck('id')->all();
            $poster = app(JournalPoster::class);

            for ($i = 0; $i < $count; $i++) {
                $lines = [];
                $total = 0;

                for ($n = mt_rand(1, 4); $n > 0; $n--) {
                    $amount = mt_rand(1, 50_000_000);
                    $total += $amount;
                    $lines[] = new LineInput($accounts[array_rand($accounts)], LineInput::DEBIT, Money::ofMinor($amount, 'KES'));
                }

                // Credits split the same total over one to three accounts.
                $parts = mt_rand(1, 3);
                $left = $total;

                for ($p = 1; $p <= $parts; $p++) {
                    $amount = $p === $parts ? $left : intdiv($left, 2);
                    $left -= $amount;

                    if ($amount > 0) {
                        $lines[] = new LineInput($accounts[array_rand($accounts)], LineInput::CREDIT, Money::ofMinor($amount, 'KES'));
                    }
                }

                $poster->record($this->acme, ['date' => CarbonImmutable::parse('2026-0'.mt_rand(1, 9).'-'.str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT)), 'kind' => 'system',
                    'source_type' => 'test.random', 'source_id' => (string) Str::uuid7()], $lines, $this->owner);
            }
        });
    }

    public function test_the_trial_balance_always_balances_and_the_statements_agree(): void
    {
        foreach ([11, 23, 37] as $seed) {
            $this->randomJournals(15, $seed);

            foreach ([['2026-01-01', '2026-12-31'], ['2026-03-15', '2026-06-30'], ['2026-07-01', '2026-07-31']] as [$from, $to]) {
                $tb = $this->api('GET', $this->companyUri('reports/trial-balance')."?from={$from}&to={$to}")->assertOk()->json('data');
                $this->assertTrue($tb['balanced'], "seed {$seed} {$from}..{$to}");
                $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);

                $bs = $this->api('GET', $this->companyUri('reports/balance-sheet')."?to={$to}")->assertOk()->json('data');
                $this->assertTrue($bs['balanced'], "balance sheet seed {$seed} at {$to}");

                $cf = $this->api('GET', $this->companyUri('reports/cash-flow')."?from={$from}&to={$to}")->assertOk()->json('data');
                $this->assertTrue($cf['reconciles'], "cash flow seed {$seed} {$from}..{$to}");
                $this->assertSame($cf['closing_cash'] - $cf['opening_cash'], $cf['net_change']);
            }
        }
    }

    public function test_the_general_ledger_drills_down_with_a_running_balance_to_the_journal_and_its_source(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '300.00']]);
        $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '200.00']]);
        $account = $this->account('4100');

        $gl = $this->api('GET', $this->companyUri('reports/general-ledger')."?account={$account->id}&from=2026-10-01&to=2026-10-31")->assertOk()->json('data');
        $this->assertSame([-30000, -50000], array_column($gl['lines'], 'balance'));
        $this->assertSame(['accounting.document', $invoice['id']], [$gl['lines'][0]['journal']['source_type'], $gl['lines'][0]['journal']['source_id']]);
        // The drill-down names its source by the document's number.
        $this->assertSame($this->api('GET', 'accounting/documents/'.$invoice['id'])->json('data.number'), $gl['lines'][0]['journal']['source_number']);
        $this->assertNotNull($gl['lines'][0]['journal']['source_number']);
        $this->api('GET', 'accounting/journals/'.$gl['lines'][0]['journal']['id'])->assertOk()->assertJsonPath('data.source.id', $invoice['id']);

        $pl = $this->api('GET', $this->companyUri('reports/profit-and-loss').'?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertSame(50000, $pl['sections']['revenue']['total']);
        $this->assertSame(50000, $pl['net_profit']);
    }

    public function test_budget_against_actual_by_account_and_cost_centre(): void
    {
        $centre = $this->inTenant(fn () => CostCentre::create(['company_id' => $this->acme->id, 'code' => 'OPS', 'name' => 'Operations']));
        $budget = $this->api('POST', $this->companyUri('budgets'), ['name' => 'Plan 2026', 'fiscal_year' => 2026])->assertCreated()->json('data');
        $this->api('PUT', "accounting/budgets/{$budget['id']}/cells", ['cells' => [
            ['account_id' => $this->account('6200')->id, 'cost_centre_id' => $centre->id, 'month' => 10, 'amount' => '20000.00'],
            ['account_id' => $this->account('6200')->id, 'cost_centre_id' => $centre->id, 'month' => 11, 'amount' => '20000.00'],
        ]])->assertOk();
        $this->inTenant(fn () => app(JournalPoster::class)->record($this->acme, ['date' => CarbonImmutable::parse('2026-10-05'), 'kind' => 'system', 'source_type' => 'test.rent', 'source_id' => (string) Str::uuid7()], [
            new LineInput($this->account('6200')->id, LineInput::DEBIT, Money::ofMinor(1800000, 'KES'), dimensions: ['cost_centre_id' => $centre->id]),
            new LineInput($this->account('1120')->id, LineInput::CREDIT, Money::ofMinor(1800000, 'KES')),
        ], $this->owner));

        $rows = $this->api('GET', "accounting/budgets/{$budget['id']}/vs-actual?from=2026-10-01&to=2026-10-31")->assertOk()->json('data.rows');
        $this->assertSame([['6200', 'Operations', 2000000, 1800000, 200000]], array_map(fn ($r) => [$r['account']['code'], $r['cost_centre']['name'], $r['budget'], $r['actual'], $r['variance']], $rows));
    }

    public function test_consolidation_translates_each_company_and_eliminates_intercompany_balances(): void
    {
        $congo = $this->inTenant(function () {
            $congo = $this->company('Acme Congo');
            $congo->forceFill(['base_currency' => 'USD', 'country' => 'CD'])->save();
            app(TenantCurrencies::class)->provisionFor($congo);
            app(Charts::class)->fromTemplate($congo);

            return $congo;
        });
        $this->rate('USD', 'KES', '129.5', at: '2026-09-01T00:00:00Z', company: $congo);
        $dueFrom = $this->api('POST', $this->companyUri('accounts'), ['code' => '1250', 'name' => 'Due from Acme Congo', 'type' => 'asset', 'subtype' => 'intercompany',
            'parent_id' => $this->account('1100')->id, 'counterparty_company_id' => $congo->id])->assertCreated()->json('data.id');
        $dueTo = $this->api('POST', "companies/{$congo->id}/accounting/accounts", ['code' => '481', 'name' => 'Due to Acme', 'type' => 'liability', 'subtype' => 'intercompany',
            'counterparty_company_id' => $this->acme->id])->assertCreated()->json('data.id');

        $this->inTenant(function () use ($congo, $dueFrom, $dueTo) {
            $poster = app(JournalPoster::class);
            $poster->record($this->acme, ['date' => CarbonImmutable::parse('2026-10-05'), 'kind' => 'system', 'source_type' => 'test.ic', 'source_id' => (string) Str::uuid7()], [
                new LineInput($dueFrom, LineInput::DEBIT, Money::ofMinor(1295000, 'KES')),
                new LineInput($this->account('4200')->id, LineInput::CREDIT, Money::ofMinor(1295000, 'KES')),
            ], $this->owner);
            $expense = Account::query()->where('company_id', $congo->id)->where('code', '622')->value('id');
            $poster->record($congo, ['date' => CarbonImmutable::parse('2026-10-05'), 'kind' => 'system', 'source_type' => 'test.ic', 'source_id' => (string) Str::uuid7()], [
                new LineInput($expense, LineInput::DEBIT, Money::ofMinor(10000, 'USD')),
                new LineInput($dueTo, LineInput::CREDIT, Money::ofMinor(10000, 'USD')),
            ], $this->owner);
        });

        $report = $this->api('GET', 'accounting/consolidation?currency=KES&from=2026-01-01&to=2026-10-31')->assertOk()->json('data');

        $this->assertSame(['assets' => 1295000, 'liabilities' => 1295000, 'difference' => 0], $report['eliminations']);
        $this->assertSame(1295000, $report['sections']['income']);
        $this->assertSame(1295000, $report['sections']['expense']);
        $this->assertSame(0, $report['net_profit']);
        $this->assertTrue($report['balanced']);
        $this->assertSame(['129.50000000', 'USD', 'KES'], array_values($report['companies'][0]['closing_rate'] ?? $report['companies'][1]['closing_rate']));
    }
}
