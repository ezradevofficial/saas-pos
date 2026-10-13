<?php

namespace Modules\Accounting\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** ACC-08, ACC-15: period close and reopen, closing entries, and revaluation maths. */
class PeriodsAndFxTest extends TestCase
{
    use BuildsAccounting, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    private function period(string $startsOn): Period
    {
        $this->api('GET', $this->companyUri('periods').'?year='.substr($startsOn, 0, 4))->assertOk();

        return $this->inTenant(fn () => Period::query()->where('company_id', $this->acme->id)->where('starts_on', $startsOn)->sole());
    }

    private function postManual(string $date, array $lines): string
    {
        $id = $this->api('POST', $this->companyUri('journals'), $this->journalBody($date, $lines))->assertCreated()->json('data.id');
        $this->inTenant(fn () => app(JournalPoster::class)->post(Journal::findOrFail($id), $this->owner));

        return $id;
    }

    public function test_the_year_end_close_posts_the_closing_entry_locks_the_year_and_reopening_reverses_it(): void
    {
        $this->postManual('2026-03-10', [['1120', '5000.00', null], ['4100', null, '5000.00']]);
        $this->postManual('2026-04-10', [['6200', '1200.00', null], ['1120', null, '1200.00']]);
        $draft = $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-05-02', [['6200', '1.00', null], ['1120', null, '1.00']]))->json('data.id');
        $december = $this->period('2026-12-01');

        $this->api('POST', "accounting/periods/{$december->id}/close")->assertUnprocessable()->assertJsonPath('code', 'period_has_drafts');
        $this->api('POST', "accounting/journals/{$draft}/cancel", ['reason' => 'Not needed'])->assertOk();
        $this->api('POST', "accounting/periods/{$december->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->inTenant(function () {
            $this->assertSame(0, Period::query()->where('company_id', $this->acme->id)->where('fiscal_year', 2026)->where('status', 'open')->count());
            $closing = Journal::query()->where('kind', 'closing')->sole();
            $retained = JournalLine::query()->where('journal_id', $closing->id)->where('account_id', $this->account('3200')->id)->sole();
            $this->assertSame(380000, $retained->base_credit_minor);
        });

        $tb = collect($this->api('GET', $this->companyUri('reports/trial-balance').'?from=2026-01-01&to=2026-12-31')->json('data.rows'))->keyBy('account.code');
        $this->assertSame(0, $tb['4100']['closing']);
        $this->assertSame(-380000, $tb['3200']['closing']);
        // The P&L leaves the closing entry out.
        $this->api('GET', $this->companyUri('reports/profit-and-loss').'?from=2026-01-01&to=2026-12-31')->assertJsonPath('data.net_profit', 380000);

        // Nothing is even drafted into the closed year.
        $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-06-01', [['6200', '1.00', null], ['1120', null, '1.00']]))
            ->assertUnprocessable()->assertJsonPath('code', 'period_closed');
        $november = $this->period('2026-11-01');
        $this->api('POST', "accounting/periods/{$november->id}/reopen", ['reason' => 'Late invoice'])->assertUnprocessable()->assertJsonPath('code', 'period_reopen_order');

        $reopener = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->api('POST', "accounting/periods/{$december->id}/reopen", ['reason' => 'Late invoice'], $reopener)->assertOk()->assertJsonPath('data.status', 'open');
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'accounting.period.reopen')->count()));
        $tb = collect($this->api('GET', $this->companyUri('reports/trial-balance').'?from=2026-01-01&to=2026-12-31')->json('data.rows'))->keyBy('account.code');
        $this->assertSame(-500000, $tb['4100']['closing']);
        $this->assertSame(0, $tb['3200']['closing']);
    }

    public function test_reopening_needs_its_permission(): void
    {
        $period = $this->period('2026-01-01');
        $this->api('POST', "accounting/periods/{$period->id}/close")->assertOk();
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $this->api('POST', "accounting/periods/{$period->id}/reopen", ['reason' => 'x x x'], $auditor)->assertForbidden();
    }

    public function test_revaluation_posts_the_integer_half_up_difference_and_reverses_it_the_next_day(): void
    {
        $this->rate('USD', 'KES', '129.5', at: '2026-10-01T00:00:00Z');
        // USD 100.01 at 129.5 = KES 12,951.295 → 1295130 minor (half up).
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.01']], ['currency' => 'USD']);
        $this->assertSame('1295130', $invoice['base_total']['amount_minor']);

        // At 133.333333: 10001 × 133.333333 = 1333466.66... → 1333467; the difference is 38337.
        $this->rate('USD', 'KES', '133.333333', at: '2026-10-20T00:00:00Z');
        $october = $this->period('2026-10-01');
        $result = $this->api('POST', "accounting/periods/{$october->id}/revalue")->assertCreated()->json('data');

        $this->assertSame(38337, $result['items'][0]['adjustment']);
        $this->inTenant(function () use ($result) {
            $journal = Journal::query()->findOrFail($result['journal_id']);
            $reversal = Journal::query()->findOrFail($result['reversal_journal_id']);
            $this->assertSame(['2026-10-31', '2026-11-01'], [$journal->date->toDateString(), $reversal->date->toDateString()]);
            $gain = JournalLine::query()->where('journal_id', $journal->id)->where('account_id', $this->account('7100')->id)->sole();
            $this->assertSame(38337, $gain->base_credit_minor);
        });

        $this->api('POST', "accounting/periods/{$october->id}/revalue")->assertUnprocessable()->assertJsonPath('code', 'revaluation_exists');
        $ar = fn (string $to) => collect($this->api('GET', $this->companyUri('reports/trial-balance')."?from=2026-01-01&to={$to}")->json('data.rows'))->keyBy('account.code')['1200']['closing'];
        $this->assertSame(1333467, $ar('2026-10-31'));
        $this->assertSame(1295130, $ar('2026-11-01'));
    }

    public function test_revaluation_budgets_and_consolidation_need_accounting_pro(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('accounting_pro'));
        $october = $this->period('2026-10-01');

        $this->api('POST', "accounting/periods/{$october->id}/revalue")->assertForbidden()->assertJsonPath('code', 'module_inactive');
        $this->api('GET', $this->companyUri('budgets'))->assertForbidden();
        $this->api('GET', 'accounting/consolidation')->assertForbidden();
    }
}
