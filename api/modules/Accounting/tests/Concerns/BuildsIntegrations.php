<?php

namespace Modules\Accounting\Tests\Concerns;

use App\Core\Rbac\ModuleRegistry;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Inventory\Models\CostState;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Tests\Concerns\BuildsExchangeRates;

/**
 * Stage 7B: BuildsLoyalty (POS and Loyalty on Acme, Amina, the soap at
 * KES 562.50 tax included at a synthetic 12.5 %) with Inventory and
 * Accounting (Pro unless asked otherwise) on, the KE chart and its
 * default posting rules, ten soaps in opening stock at KES 300.00 each,
 * and a promotion of 25 % off everything.
 */
trait BuildsIntegrations
{
    use BuildsExchangeRates, BuildsLoyalty;

    protected string $promotion;

    protected function setUpIntegrations(bool $accounting = true, bool $pro = true, bool $congo = false): void
    {
        $this->setUpLoyalty();

        if ($congo) {
            // DR Congo: base USD, CDF too (before anything posts, so the base locks as USD).
            $this->congoCurrencies();
        }

        $this->inTenant(function () use ($accounting, $pro) {
            $modules = app(ModuleRegistry::class);
            $modules->activate('inventory');

            if ($accounting) {
                $modules->activate('accounting');
                $pro ? $modules->activate('accounting_pro') : null;
                app(Charts::class)->fromTemplate($this->acme);
            }
        });
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'qty' => '10', 'unit_cost' => '300'], $this->headersFor())->assertCreated();
        $this->inTenant(fn () => $this->branchA->forceFill(['timezone' => 'UTC'])->save());
        $this->promotion = $this->postJson('/api/v1/loyalty/promotions', [
            'company_id' => $this->acme->id, 'name' => 'Quarter off', 'type' => 'percent', 'percent' => '25', 'priority' => 5, 'schedule' => [], 'status' => 'active',
        ], $this->headersFor())->assertCreated()->json('data.id');
        $this->travel(10)->minutes();
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
    }

    /** Two soaps with the promotion: KES 1,125.00 less KES 281.25, tax KES 93.75 of KES 843.75. */
    protected function promotedLine(): array
    {
        return $this->line(['discount_minor' => '28125', 'tax_minor' => '9375', 'total_minor' => '84375', 'adjustments' => [
            ['source' => 'loyalty.promotion', 'reference' => $this->promotion, 'amount_minor' => '28125'],
        ]]);
    }

    protected function accountId(string $code): string
    {
        return $this->inTenant(fn () => Account::query()->where('company_id', $this->acme->id)->where('code', $code)->value('id'));
    }

    /** An account's balance (base debits less credits, minor units) over every posted journal. */
    protected function balance(string $code): int
    {
        return $this->inTenant(fn () => (int) JournalLine::query()->where('account_id', Account::query()->where('company_id', $this->acme->id)->where('code', $code)->value('id'))
            ->whereIn('journal_id', Journal::query()->where('status', 'posted')->select('id'))
            ->selectRaw('coalesce(sum(base_debit_minor - base_credit_minor), 0) as balance')->value('balance'));
    }

    /** @return list<Journal> the posted journals of a source type */
    protected function journalsOf(string $sourceType): array
    {
        return $this->inTenant(fn () => Journal::query()->where('source_type', $sourceType)->where('status', 'posted')->orderBy('created_at')->get()->all());
    }

    /** @return array<string, array{0: int, 1: int}> a journal's lines by account code: [base debit, base credit] */
    protected function linesOf(Journal $journal): array
    {
        return $this->inTenant(function () use ($journal) {
            $lines = [];

            foreach (JournalLine::query()->where('journal_id', $journal->id)->get() as $line) {
                $code = Account::query()->whereKey($line->account_id)->value('code');
                $lines[$code] = [($lines[$code][0] ?? 0) + (int) $line->base_debit_minor, ($lines[$code][1] ?? 0) + (int) $line->base_credit_minor];
            }

            ksort($lines);

            return $lines;
        });
    }

    /** The company's stock valuation (INV-20): its items' cost-state values. */
    protected function valuation(): int
    {
        return $this->inTenant(fn () => (int) CostState::query()->where('company_id', $this->acme->id)->sum('value_minor'));
    }

    protected function assertBalanced(): void
    {
        $this->inTenant(function () {
            $sums = JournalLine::query()->whereIn('journal_id', Journal::query()->where('status', 'posted')->select('id'))
                ->selectRaw('sum(base_debit_minor) as debits, sum(base_credit_minor) as credits')->first();
            $this->assertSame((int) $sums->debits, (int) $sums->credits, 'the ledger balances');
        });
    }
}
