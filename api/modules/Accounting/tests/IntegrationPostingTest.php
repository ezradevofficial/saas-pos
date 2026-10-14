<?php

namespace Modules\Accounting\Tests;

use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Integrations\Integrations;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Models\QueuedSource;
use Modules\Accounting\Tests\Concerns\BuildsIntegrations;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleSnapshot;
use Modules\POS\Models\Sale;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ACC-24 to ACC-29 (stage 7B): POS, Inventory and Loyalty post through the
 * posting rules, end to end through the till's real upload API, with all
 * four modules on.
 */
class IntegrationPostingTest extends TestCase
{
    use BuildsIntegrations, RefreshTenantDatabase;

    /** A gift card sold in the back office for KES 1,000.00 (inactive, then sold); returns its code. */
    private function soldCard(): string
    {
        [$card, $code] = $this->giftCard('100000', active: false);
        $this->inTenant(fn () => app(GiftCards::class)->sell($card, null, 'MPESA-GC1', $this->owner));

        return $code;
    }

    public function test_a_promoted_sale_paid_by_gift_card_and_cash_then_a_partial_refund_and_a_void_post_balanced_idempotent_journals(): void
    {
        $this->setUpIntegrations();
        $code = $this->soldCard();
        $shift = $this->openShift();

        // Sale 1: two soaps with 25 % off (KES 843.75): KES 500.00 by gift card and KES 343.75 cash, for Amina.
        $sale = $this->saleBody($shift, 1, ['customer_id' => $this->amina->id, 'lines' => [$this->promotedLine()], 'payments' => [
            $this->pay('voucher', '50000', ['code_hash' => GiftCards::proof($code)]), $this->pay('cash_kes', '34375'),
        ]]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        [$journal] = $this->journalsOf('pos.sale');
        // Revenue before the promotion (net 750.00 + the promotion's net 250.00), the promotion apart, tax per code.
        $this->assertSame([
            '1110' => [34375, 0], '1230' => [50000, 0], '2200' => [0, 9375], '4100' => [0, 100000], '4300' => [25000, 0],
        ], $this->linesOf($journal));
        $this->assertEquals(['kind' => 'pos.sale', 'id' => $sale['id']], $journal->source_link);
        // The card's liability moves to the tender; the clearing account nets to zero.
        $this->assertSame(['1230' => [0, 50000], '2310' => [50000, 0]], $this->linesOf($this->journalsOf('loyalty.gift_card_redeemed')[0]));
        $this->assertSame(0, $this->balance('1230'));
        // The card sold in the back office: cash in, a liability, never revenue.
        $this->assertSame(['1110' => [100000, 0], '2310' => [0, 100000]], $this->linesOf($this->journalsOf('loyalty.gift_card_sold')[0]));
        // 8 points at KES 1.00 each: a loyalty cost and a liability.
        $this->assertSame(['2320' => [0, 800], '6910' => [800, 0]], $this->linesOf($this->journalsOf('loyalty.points_earned')[0]));
        // Cost of sales per sale line: two soaps at KES 300.00.
        $this->assertSame(['1300' => [0, 60000], '5100' => [60000, 0]], $this->linesOf($this->journalsOf('inventory.cost_of_sales')[0]));

        // A partial refund: one soap back, KES 421.88 in cash (its share of tax and of the promotion).
        $this->refundSale($sale, $shift, '1', '42188', 1)->assertOk()->assertJsonPath('results.0.status', 'stored');
        [$refund] = $this->journalsOf('pos.refund');
        $this->assertSame([
            '1110' => [0, 42188], '2200' => [4688, 0], '4100' => [50000, 0], '4300' => [0, 12500],
        ], $this->linesOf($refund));
        // Four of the eight points are reversed (floor of 8 × 421.88 / 843.75).
        $reversal = collect($this->journalsOf('loyalty.points_earned'))->first(fn (Journal $j) => ($this->linesOf($j)['2320'][0] ?? 0) > 0);
        $this->assertSame(['2320' => [400, 0], '6910' => [0, 400]], $this->linesOf($reversal));

        // Sale 2, voided: its journal is reversed at the void's date, the stock comes back.
        $second = $this->saleBody($shift, 2);
        $this->upload([$second])->assertOk();
        $this->voidSale($second['id'])->assertOk();
        $sold = collect($this->journalsOf('pos.sale'))->firstWhere('source_id', $second['id']);
        [$void] = $this->journalsOf('pos.void');
        $this->assertSame(['1110' => [112500, 0], '2200' => [0, 12500], '4100' => [0, 100000]], $this->linesOf($sold));
        $this->assertSame(['1110' => [0, 112500], '2200' => [12500, 0], '4100' => [100000, 0]], $this->linesOf($void));

        // The ledger balances, nothing waits as an exception, and the stock account is the inventory valuation.
        $this->assertBalanced();
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->where('status', 'open')->count()));
        $this->assertSame(270000, $this->valuation());
        $this->assertSame($this->valuation(), $this->balance('1300'));

        // Replayed (the backfill over the same days): nothing posts twice.
        $journals = $this->inTenant(fn () => Journal::query()->count());
        $this->artisan('accounting:backfill', ['--from' => now()->subDay()->toDateString()])->assertSuccessful();
        $this->assertSame($journals, $this->inTenant(fn () => Journal::query()->count()));
        $this->assertBalanced();
    }

    public function test_with_accounting_off_nothing_is_posted(): void
    {
        $this->setUpIntegrations(accounting: false);
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1, ['customer_id' => $this->amina->id]);
        $this->upload([$sale])->assertOk();
        $this->voidSale($sale['id'])->assertOk();

        $this->inTenant(function () {
            $this->assertSame(0, Journal::query()->count());
            $this->assertSame(0, PostingException::query()->count());
        });
    }

    public function test_a_missing_rule_keeps_an_exception_that_posts_on_retry(): void
    {
        $this->setUpIntegrations();
        $rule = $this->inTenant(fn () => PostingRule::query()->where('event_type', 'pos.sale')->value('id'));
        $this->postJson("/api/v1/accounting/posting-rules/{$rule}/archive", [], $this->headersFor())->assertOk();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1);
        $this->upload([$sale])->assertOk();

        $exception = $this->getJson("/api/v1/companies/{$this->acme->id}/accounting/posting-exceptions", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.source_type', 'pos.sale')->assertJsonPath('data.0.error_code', 'posting_rule_missing')
            ->assertJsonPath('data.0.link.kind', 'pos.sale')->assertJsonPath('data.0.link.id', $sale['id'])->json('data.0.id');
        $this->assertSame([], $this->journalsOf('pos.sale'));

        // The defaults bring the rule back; the retry posts the kept sale.
        $this->postJson("/api/v1/companies/{$this->acme->id}/accounting/posting-rules/defaults", [], $this->headersFor())->assertOk()->assertJsonPath('data.rules', 1);
        $this->postJson("/api/v1/accounting/posting-exceptions/{$exception}/retry", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertCount(1, $this->journalsOf('pos.sale'));
        $this->assertSame('resolved', $this->inTenant(fn () => PostingException::query()->findOrFail($exception)->status));
    }

    public function test_a_rule_line_without_an_account_for_a_key_keeps_an_exception(): void
    {
        $this->setUpIntegrations();
        $this->inTenant(function () {
            $rule = PostingRule::query()->where('event_type', 'pos.sale')->firstOrFail();
            $lines = $rule->lines;
            $lines[0]['accounts'] = [];
            $rule->forceFill(['lines' => $lines])->save();
        });
        $shift = $this->openShift();
        $this->upload([$this->saleBody($shift, 1)])->assertOk();

        $this->assertSame('posting_account_missing', $this->inTenant(fn () => PostingException::query()->where('source_type', 'pos.sale')->value('error_code')));
    }

    public function test_a_sale_dated_in_a_closed_period_waits_and_posts_once_the_period_reopens(): void
    {
        $this->setUpIntegrations();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1);
        $this->upload([$sale])->assertOk();
        $periods = $this->getJson("/api/v1/companies/{$this->acme->id}/accounting/periods?year=2026", $this->headersFor())->json('data');
        $this->postJson("/api/v1/accounting/periods/{$periods[0]['id']}/close", [], $this->headersFor())->assertOk();

        // A sale of January (a till offline since then) reaches Accounting now.
        $snapshot = $this->inTenant(fn () => SaleSnapshot::sale(Sale::query()->findOrFail($sale['id'])));
        $january = [...$snapshot, 'id' => $this->id(), 'sold_at' => '2026-01-20T10:00:00.000000Z'];
        $result = $this->inTenant(fn () => app(Integrations::class)->handle(new SaleCompleted($this->owner->tenant_id, $january['id'], $this->acme->id, $this->locationA->id, $january)));

        $this->assertFalse($result->posted());
        $this->assertSame('period_closed', $result->exception->error_code);

        $this->postJson("/api/v1/accounting/periods/{$periods[0]['id']}/reopen", ['reason' => 'Late till sales'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/accounting/posting-exceptions/{$result->exception->id}/retry", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('2026-01-20', collect($this->journalsOf('pos.sale'))->firstWhere('source_id', $january['id'])->date->toDateString());
    }

    public function test_a_foreign_currency_sale_posts_at_its_own_rate_with_pro_and_waits_with_essentials(): void
    {
        $this->setUpIntegrations();
        $this->rate('USD', 'KES', '129.50', at: '-1 day');
        $shift = $this->openShift();
        $usd = fn (int $seq) => $this->saleBody($shift, $seq, [
            'currency' => 'USD', 'price_list_id' => null,
            'lines' => [$this->line(['qty' => '1', 'unit_price_minor' => '850', 'list_price_minor' => null, 'price_list_id' => null, 'tax_minor' => '94', 'total_minor' => '850'])],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => '850', 'amount_in_sale_minor' => '850', 'rate' => null, 'status' => 'confirmed']],
            'change' => ['currency' => 'USD', 'amount_minor' => '0', 'rate' => null],
        ]);
        $first = $usd(1);
        $this->upload([$first])->assertOk();

        // USD 8.50 at 129.50: KES 1,100.75 (tax KES 121.73, revenue KES 979.02; per line, half up).
        [$journal] = $this->journalsOf('pos.sale');
        $this->assertSame(['1110' => [110075, 0], '2200' => [0, 12173], '4100' => [0, 97902]], $this->linesOf($journal));
        $this->assertSame('USD', $this->inTenant(fn () => $journal->lines()->first()->currency));

        // Essentials: the sale waits, the ledger is untouched.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('accounting_pro'));
        $second = $usd(2);
        $this->upload([$second])->assertOk();
        $this->assertSame('accounting_pro_required', $this->inTenant(fn () => PostingException::query()->where('source_id', $second['id'])->value('error_code')));
        $this->assertCount(1, $this->journalsOf('pos.sale'));
    }

    public function test_the_backfill_posts_what_was_recorded_before_accounting_once(): void
    {
        $this->setUpIntegrations(accounting: false);
        $shift = $this->openShift();
        $this->upload([$this->saleBody($shift, 1, ['customer_id' => $this->amina->id]), $this->saleBody($shift, 2)])->assertOk();
        $this->inTenant(fn () => $this->assertSame(0, Journal::query()->count()));

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('accounting');
            app(ModuleRegistry::class)->activate('accounting_pro');
            app(Charts::class)->fromTemplate($this->acme);
        });
        $this->artisan('accounting:backfill')->assertExitCode(2);
        $this->artisan('accounting:backfill', ['--from' => now()->subDay()->toDateString()])->assertSuccessful();

        $this->assertCount(2, $this->journalsOf('pos.sale'));
        $this->assertCount(2, $this->journalsOf('inventory.cost_of_sales'));
        $this->assertCount(1, $this->journalsOf('inventory.opening'));
        $this->assertCount(1, $this->journalsOf('loyalty.points_earned'));
        $this->assertSame($this->valuation(), $this->balance('1300'));
        $count = $this->inTenant(fn () => Journal::query()->count());

        $this->artisan('accounting:backfill', ['--from' => now()->subDay()->toDateString()])->assertSuccessful();
        $this->assertSame($count, $this->inTenant(fn () => Journal::query()->count()));
        $this->assertBalanced();
    }

    public function test_a_daily_summary_posts_one_journal_per_location_and_day(): void
    {
        $this->setUpIntegrations();
        $this->putJson("/api/v1/companies/{$this->acme->id}/accounting/settings", ['sales_posting' => 'daily_summary'], $this->headersFor())
            ->assertOk()->assertJsonPath('data.sales_posting', 'daily_summary');
        $shift = $this->openShift();
        $this->upload([$this->saleBody($shift, 1), $this->saleBody($shift, 2)])->assertOk();

        $this->assertSame([], $this->journalsOf('pos.sale'));
        $this->assertSame(2, $this->inTenant(fn () => QueuedSource::query()->whereNull('summarised_at')->count()));

        // Not before the day has ended.
        $this->artisan('accounting:post-sales-summaries')->assertSuccessful();
        $this->assertSame([], $this->journalsOf('pos.sale_summary'));

        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $this->artisan('accounting:post-sales-summaries')->assertSuccessful();
        $this->artisan('accounting:post-sales-summaries')->assertSuccessful();

        [$summary] = $this->journalsOf('pos.sale_summary');
        $this->assertCount(1, $this->journalsOf('pos.sale_summary'));
        $this->assertSame(['1110' => [225000, 0], '2200' => [0, 25000], '4100' => [0, 200000]], $this->linesOf($summary));
        $this->assertSame(0, $this->inTenant(fn () => QueuedSource::query()->whereNull('summarised_at')->count()));
    }

    /**
     * Review #1: a tender posts in its own currency and amount, at the rate the till used; here a
     * KES sale paid in US dollars into a cash account kept in USD.
     */
    public function test_a_foreign_tender_posts_in_its_own_currency_to_an_account_in_that_currency(): void
    {
        $this->setUpIntegrations();
        $this->rate('USD', 'KES', '129.50', at: '-1 day');
        $this->inTenant(function () {
            $account = \Modules\Accounting\Models\Account::query()->create([
                'company_id' => $this->acme->id, 'code' => '1115', 'name' => 'Cash on hand USD', 'type' => 'asset', 'subtype' => 'cash',
                'parent_id' => \Modules\Accounting\Models\Account::query()->where('code', '1100')->value('id'), 'currency' => 'USD', 'cash_flow' => 'cash',
            ]);
            \Modules\Accounting\Models\BankAccount::query()->create(['company_id' => $this->acme->id, 'name' => 'Till USD', 'kind' => 'cash', 'account_id' => $account->id,
                'currency' => 'USD', 'payment_method_id' => $this->methods['cash_usd']->id]);
        });
        $shift = $this->openShift();
        $rate = ['rate' => '129.5', 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop', 'effective_at' => now()->subDay()->toIso8601String()];
        // KES 1,125.00 paid with USD 8.69 (KES 1,125.355): change KES 0.35, the shop keeps KES 0.01.
        $sale = $this->saleBody($shift, 1, [
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => '869', 'amount_in_sale_minor' => '112535', 'rate' => $rate, 'status' => 'confirmed']],
            'change' => ['currency' => 'KES', 'amount_minor' => '35', 'rate' => null],
        ]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()), json_encode($this->inTenant(fn () => PostingException::query()->pluck('error_code'))));
        [$journal] = $this->journalsOf('pos.sale');
        $usd = $this->inTenant(fn () => $journal->lines()->where('currency', 'USD')->sole());
        $this->assertSame([869, 112536], [(int) $usd->debit_minor, (int) $usd->base_debit_minor]);
        $this->assertSame(['1110' => [0, 34], '1115' => [112536, 0], '2200' => [0, 12500], '4100' => [0, 100000], '7200' => [0, 2]], $this->linesOf($journal));
    }

    /**
     * Review #1: a CDF sale part-paid in USD at the till's own rate (2,900) while the sale's base
     * rate is 2,850: the dollars post as dollars, and what the two rates make the tenders differ by
     * against the sale's total goes to exchange losses.
     */
    public function test_a_tender_at_another_rate_than_the_sale_posts_the_difference_to_fx(): void
    {
        $this->setUpIntegrations(congo: true);
        $this->rate('USD', 'CDF', '2850', at: '-1 day');
        $cdf = $this->inTenant(fn () => \App\Core\MasterData\PaymentMethods\PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'cash', 'name' => 'Cash CDF', 'currency' => 'CDF', 'active' => true, 'position' => 9]));
        $shift = $this->openShift();
        $tillRate = ['rate' => '2900', 'base' => 'USD', 'quote' => 'CDF', 'kind' => 'shop', 'effective_at' => now()->subDay()->toIso8601String()];
        $sale = $this->saleBody($shift, 1, [
            'currency' => 'CDF', 'price_list_id' => null,
            'lines' => [$this->line(['qty' => '1', 'unit_price_minor' => '28500', 'list_price_minor' => null, 'price_list_id' => null, 'tax_minor' => '3167', 'total_minor' => '28500'])],
            'payments' => [
                ['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => '500', 'amount_in_sale_minor' => '14500', 'rate' => $tillRate, 'status' => 'confirmed'],
                ['id' => $this->id(), 'payment_method_id' => $cdf->id, 'currency' => 'CDF', 'amount_minor' => '14000', 'amount_in_sale_minor' => '14000', 'rate' => null, 'status' => 'confirmed'],
            ],
            'change' => ['currency' => 'CDF', 'amount_minor' => '0', 'rate' => null],
        ]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()), json_encode($this->inTenant(fn () => PostingException::query()->pluck('error_message'))));
        [$journal] = $this->journalsOf('pos.sale');
        // USD 5.00 and CDF 14,000 (USD 4.91) against CDF 28,500 at 2,850 (USD 10.00): USD 0.09 lost.
        // (The company is now Congolese: the SYSCOHADA chart, cash 571, VAT 443, sales 701, exchange losses 676.)
        $this->assertSame(['443' => [0, 111], '571' => [991, 0], '676' => [9, 0], '701' => [0, 889]], $this->linesOf($journal));
        $this->assertSame('500', $this->inTenant(fn () => (string) $journal->lines()->where('currency', 'USD')->where('account_id', $this->accountId('571'))->sole()->debit_minor));
    }

    /** The daily summaries run two days on (the sales' day has ended), then the clock comes back for the API's tokens. */
    private function summariesLater(): void
    {
        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $this->artisan('accounting:post-sales-summaries')->assertSuccessful();
        $this->travelBack();
    }

    /** Daily summary mode, two sales of today, and the pos.sale rule archived so the summary fails. */
    private function failedSummary(): array
    {
        $this->setUpIntegrations();
        $this->putJson("/api/v1/companies/{$this->acme->id}/accounting/settings", ['sales_posting' => 'daily_summary'], $this->headersFor())->assertOk();
        $shift = $this->openShift();
        $soldAt = now()->subMinutes(5)->toIso8601String();
        $this->upload([$this->saleBody($shift, 1), $this->saleBody($shift, 2)])->assertOk();
        $rule = $this->inTenant(fn () => PostingRule::query()->where('event_type', 'pos.sale')->value('id'));
        $this->postJson("/api/v1/accounting/posting-rules/{$rule}/archive", [], $this->headersFor())->assertOk();

        $this->summariesLater();
        $failed = $this->inTenant(fn () => PostingException::query()->where('source_type', 'pos.sale_summary')->sole());

        // A late sale of the same day (a till that was offline), then the rule is back.
        $this->upload([$this->saleBody($shift, 3, ['sold_at' => $soldAt])])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->postJson("/api/v1/companies/{$this->acme->id}/accounting/posting-rules/defaults", [], $this->headersFor())->assertOk();

        return [$failed->id];
    }

    /** Review #2: the run that covers a failed summary supersedes it; retrying the old one is refused. */
    public function test_a_later_summary_supersedes_a_failed_one_and_its_retry_is_refused(): void
    {
        [$failed] = $this->failedSummary();

        $this->summariesLater();
        $this->postJson("/api/v1/accounting/posting-exceptions/{$failed}/retry", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'summary_superseded');

        $this->assertCount(1, $this->journalsOf('pos.sale_summary'));
        $this->assertSame(-300000, $this->balance('4100'));
        $this->assertSame('superseded', $this->inTenant(fn () => PostingException::query()->findOrFail($failed)->status));
        $this->assertSame(0, $this->inTenant(fn () => QueuedSource::query()->whereNull('summarised_at')->count()));
    }

    /** Review #2: a retry first rebuilds the failed summary from its own sales; the run then posts only the late one. */
    public function test_a_retried_summary_posts_its_own_sales_and_the_run_only_the_rest(): void
    {
        [$failed] = $this->failedSummary();

        $this->postJson("/api/v1/accounting/posting-exceptions/{$failed}/retry", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'posted');
        $this->summariesLater();
        $this->summariesLater();

        $this->assertCount(2, $this->journalsOf('pos.sale_summary'));
        $this->assertSame(-300000, $this->balance('4100'));
        $this->assertSame(0, $this->inTenant(fn () => QueuedSource::query()->whereNull('summarised_at')->count()));
    }

    /** Review #3: per sale, then daily summary, then a backfill: the posted sale is not queued again. */
    public function test_switching_to_daily_summary_never_queues_a_posted_sale(): void
    {
        $this->setUpIntegrations();
        $shift = $this->openShift();
        $this->upload([$this->saleBody($shift, 1)])->assertOk();
        $this->putJson("/api/v1/companies/{$this->acme->id}/accounting/settings", ['sales_posting' => 'daily_summary'], $this->headersFor())->assertOk();
        $this->artisan('accounting:backfill', ['--from' => now()->subDay()->toDateString()])->assertSuccessful();
        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $this->artisan('accounting:post-sales-summaries')->assertSuccessful();

        $this->assertSame(0, $this->inTenant(fn () => QueuedSource::query()->count()));
        $this->assertSame([], $this->journalsOf('pos.sale_summary'));
        $this->assertSame(-100000, $this->balance('4100'));
    }

    /** Review #3: daily summary, then per sale, then a backfill: the queued sale is not posted on its own as well. */
    public function test_switching_to_per_sale_never_posts_a_queued_sale_twice(): void
    {
        $this->setUpIntegrations();
        $this->putJson("/api/v1/companies/{$this->acme->id}/accounting/settings", ['sales_posting' => 'daily_summary'], $this->headersFor())->assertOk();
        $shift = $this->openShift();
        $this->upload([$this->saleBody($shift, 1)])->assertOk();
        $this->putJson("/api/v1/companies/{$this->acme->id}/accounting/settings", ['sales_posting' => 'per_sale'], $this->headersFor())->assertOk();
        $this->artisan('accounting:backfill', ['--from' => now()->subDay()->toDateString()])->assertSuccessful();
        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $this->artisan('accounting:post-sales-summaries')->assertSuccessful();

        $this->assertSame([], $this->journalsOf('pos.sale'));
        $this->assertCount(1, $this->journalsOf('pos.sale_summary'));
        $this->assertSame(-100000, $this->balance('4100'));
    }

    /** Review #5: Stores issues post from Stores' own event (StoreConsumptionValued, later), never from StockMoved, and never as an exception. */
    public function test_stores_stock_movements_post_nothing_and_keep_no_exception(): void
    {
        $this->setUpIntegrations();
        $event = new \Modules\Inventory\Events\StockMoved($this->owner->tenant_id, $this->acme->id, 'KES', 'stores.issue', $this->id(), [[
            'id' => $this->id(), 'kind' => 'adjustment_out', 'item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'branch_id' => $this->branchA->id,
            'source_line_id' => $this->id(), 'qty' => '-1', 'value_minor' => '-30000', 'cost_variance_minor' => '0',
        ]], now()->toIso8601String(), $this->branchA->id);

        $this->assertNull($this->inTenant(fn () => app(Integrations::class)->handle($event)));
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()));
    }

    /**
     * Review #6: a gift card line refunded takes the card's load back (raised as a negative
     * GiftCardSold), so revenue and the card liability end at zero; a card already spent is flagged.
     */
    public function test_a_refunded_gift_card_line_reverses_the_cards_load(): void
    {
        $this->setUpIntegrations();
        $this->inTenant(fn () => $this->programme->forceFill(['gift_card_item_id' => $this->soap->id])->save());
        [$card, $code] = $this->giftCard('100000', active: false);
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1);
        $sale['extensions'] = ['loyalty' => ['gift_cards' => [['line_id' => $sale['lines'][0]['id'], 'code_hash' => GiftCards::proof($code), 'amount_minor' => '112500']]]];
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        // KES 500.00 of the card spent before the card's sale is refunded.
        $this->upload([$this->saleBody($shift, 2, ['lines' => [$this->line(['qty' => '1', 'tax_minor' => '6250', 'total_minor' => '56250'])],
            'payments' => [$this->pay('voucher', '56250', ['code_hash' => GiftCards::proof($code)])]])])->assertOk();

        $this->refundSale($sale, $shift, '2', '112500', 1)->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->inTenant(function () use ($card, $sale) {
            $this->assertSame('-56250', (string) $card->refresh()->balance_minor);
            $this->assertContains('gift_card_refunded_after_use', array_column(\Modules\Loyalty\Models\LoyaltySale::query()->findOrFail($sale['id'])->flags ?? [], 'code'));
        });
        $reversal = collect($this->journalsOf('loyalty.gift_card_sold'))->first(fn (Journal $j) => ($this->linesOf($j)['2310'][0] ?? 0) > 0);
        $this->assertSame(['2310' => [112500, 0], '4100' => [0, 112500]], $this->linesOf($reversal));
        // The card's own sale nets to nothing; what is left is the second sale (KES 500.00 net) and the KES 562.50 spent on a card now refunded.
        $this->assertSame(-50000, $this->balance('4100'));
        $this->assertSame(56250, $this->balance('2310'));
    }
}
