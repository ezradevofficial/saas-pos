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
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ACC-24 to ACC-29 (stage 7B): POS, Inventory and Loyalty post through the
 * posting rules, end to end through the till's real upload API, with all
 * four modules on.
 */
class IntegrationPostingTest extends TestCase
{
    use BuildsExchangeRates, BuildsIntegrations, RefreshTenantDatabase;

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
}
