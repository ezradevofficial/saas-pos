<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Accounting\Integrations\Integrations;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;
use Modules\FixedAssets\Models\Posting;
use Modules\FixedAssets\Postings\Postings;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-22, ACC-33: with Accounting active, every fixed-asset fact posts a
// balanced journal through the KE default rules, once (idempotent by
// posting); a closed period keeps the source as an exception; with
// Accounting off nothing posts and every figure is still kept.
class AccountingPostingTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    public function test_capitalisation_depreciation_and_disposal_post_balanced_journals(): void
    {
        $this->setUpFixedAssets(accounting: true);
        $asset = $this->active();
        $this->assertSame(['1510' => [120000000, 0], '2150' => [0, 120000000]], $this->ledger('fixed_assets.capitalisation'));

        $run = $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'], $this->accountant)->assertCreated()->json('data');
        $january = (int) $run['lines'][0]['amount']['amount_minor'];
        $this->assertSame(['1520' => [0, $january], '6500' => [$january, 0]], $this->ledger('fixed_assets.depreciation'));

        // Sold on 2026-02-28 for KES 1 180 000.00: February's charge, then the disposal at a gain.
        $disposal = $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'sale', 'disposal_date' => '2026-02-28', 'proceeds_minor' => 118000000, 'reason' => 'Sold'])->assertCreated()->json('data');
        $this->approve($disposal['id']);
        $posted = $this->api('GET', "disposals/{$disposal['id']}")->assertJsonPath('data.status', 'posted')->json('data');
        $february = (int) $posted['depreciation']['amount_minor'];
        $accumulated = $january + $february;
        $gain = (int) $posted['gain']['amount_minor'];

        $this->assertSame(['1520' => [0, $accumulated], '6500' => [$accumulated, 0]], $this->ledger('fixed_assets.depreciation'));
        $this->assertSame([
            '1240' => [118000000, 0], '1510' => [0, 120000000], '1520' => [$accumulated, 0], '4920' => [0, $gain],
        ], $this->ledger('fixed_assets.disposal'));
        $this->assertSame(118000000 - (120000000 - $accumulated), $gain);
        $this->assertGreaterThan(0, $gain);
    }

    public function test_a_disposal_at_a_loss_posts_the_loss(): void
    {
        $this->setUpFixedAssets(accounting: true);
        $asset = $this->active(['acquired_on' => '2026-10-01']);
        $disposal = $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'write_off', 'disposal_date' => '2026-10-01', 'reason' => 'Stolen'])->assertCreated()->json('data');
        $this->approve($disposal['id']);
        $posted = $this->api('GET', "disposals/{$disposal['id']}")->json('data');
        $bookValue = (int) $posted['book_value']['amount_minor'];
        $accumulated = (int) $posted['accumulated']['amount_minor'];

        $this->assertSame(['1510' => [0, 120000000], '1520' => [$accumulated, 0], '7400' => [$bookValue, 0]], $this->ledger('fixed_assets.disposal'));
    }

    public function test_the_ke_and_cd_default_rules_balance_every_source(): void
    {
        $sources = [
            'fixed_assets.capitalisation' => [['cost.c' => 1000, 'procurement' => 600, 'manual' => 400]],
            'fixed_assets.depreciation' => [['depreciation.c' => 70, 'accumulated.c' => 70]],
            // Up 200 on a book value of 700 (cost 1 000, accumulated 300): 50 reverses an earlier loss.
            'fixed_assets.revaluation' => [['accumulated.c' => 300, 'cost.c' => -100, 'surplus' => 150, 'gain' => 50, 'loss' => 0],
                ['accumulated.c' => 300, 'cost.c' => -500, 'surplus' => -120, 'gain' => 0, 'loss' => 80]],
            'fixed_assets.impairment' => [['impairment' => 30, 'surplus' => 20, 'accumulated.c' => 50]],
            // Cost 1 000, accumulated 300, sold for 800 (gain 100), surplus 50 released; and sold for 600 (loss 100).
            'fixed_assets.disposal' => [['cost.c' => 1000, 'accumulated.c' => 300, 'proceeds' => 800, 'gain' => 100, 'loss' => 0, 'book_value' => 700, 'surplus' => 50],
                ['cost.c' => 1000, 'accumulated.c' => 300, 'proceeds' => 600, 'gain' => 0, 'loss' => 100, 'book_value' => 700, 'surplus' => 0]],
        ];

        foreach (['KE', 'CD'] as $country) {
            $rules = app(\Modules\Accounting\Chart\ChartTemplates::class)->get($country)['posting_rules'];

            foreach ($sources as $type => $examples) {
                $this->assertArrayHasKey($type, $rules, "{$country} has a default rule for {$type}");

                foreach ($examples as $amounts) {
                    $debit = $credit = 0;

                    foreach ($rules[$type] as $line) {
                        $fields = str_ends_with($line['field'], '.*')
                            ? array_filter($amounts, fn ($k) => str_starts_with($k, substr($line['field'], 0, -1)), ARRAY_FILTER_USE_KEY)
                            : [$line['field'] => $amounts[$line['field']] ?? 0];

                        foreach ($fields as $value) {
                            // A negative amount posts to the other side (ACC-07).
                            $debitSide = ($line['side'] === 'debit') === ($value >= 0);
                            $debitSide ? $debit += abs($value) : $credit += abs($value);
                        }
                    }

                    $this->assertSame($debit, $credit, "{$country} {$type} balances");
                }
            }
        }
    }

    public function test_revaluation_and_impairment_post_through_the_surplus(): void
    {
        $this->setUpFixedAssets(accounting: true);
        $land = $this->active(['category_id' => $this->land->id, 'cost_minor' => 500000000]);
        $this->api('POST', "assets/{$land['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-06-30', 'value_minor' => 600000000, 'reason' => 'Valuer'])->assertCreated();
        $this->api('POST', "assets/{$land['id']}/revaluations", ['kind' => 'impairment', 'revalued_on' => '2026-07-31', 'value_minor' => 450000000, 'reason' => 'Flood'])->assertCreated();

        $this->assertSame(['1510' => [100000000, 0], '3400' => [0, 100000000]], $this->ledger('fixed_assets.revaluation'));
        $this->assertSame(['1520' => [0, 150000000], '3400' => [100000000, 0], '6510' => [50000000, 0]], $this->ledger('fixed_assets.impairment'));
    }

    public function test_postings_are_idempotent_and_a_closed_period_keeps_the_source(): void
    {
        $this->setUpFixedAssets(accounting: true);
        $this->active();

        // The same posting raised again (a retry, the backfill) posts nothing new.
        $this->inTenant(function () {
            $posting = Posting::query()->where('source_type', Postings::CAPITALISATION)->sole();
            app(Integrations::class)->handle(Postings::event($posting));
            app(Integrations::class)->handle(Postings::event($posting));
            $this->assertSame(1, Journal::query()->where('source_type', 'fixed_assets.capitalisation')->count());
        });

        // January closed: Fixed assets refuses to date anything there (decision 10).
        $this->closeBooksThrough('2026-01');
        $old = $this->draft(['name' => 'Old van', 'acquired_on' => '2026-01-10']);
        $this->api('POST', "assets/{$old['id']}/activate")->assertStatus(422)->assertJsonPath('code', 'period_closed');
        $land = $this->active(['category_id' => $this->land->id, 'cost_minor' => 500000000, 'acquired_on' => '2026-02-01', 'in_service_on' => '2026-01-01']);
        $this->api('POST', "assets/{$land['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-01-31', 'value_minor' => 1, 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'period_closed');

        // A source that reaches Accounting dated in a closed period is kept as an exception, never lost (ACC-07).
        $this->inTenant(function () {
            $posting = Posting::query()->where('source_type', Postings::CAPITALISATION)->orderByDesc('created_at')->first();
            $late = $posting->replicate(['id'])->fill(['posted_on' => '2026-01-20']);
            $late->save();
            app(Integrations::class)->handle(Postings::event($late));
            $this->assertSame('period_closed', PostingException::query()->where('source_id', $late->id)->value('error_code'));
        });
    }

    public function test_with_accounting_off_nothing_posts_and_every_figure_is_kept(): void
    {
        $this->setUpFixedAssets(accounting: false);
        $asset = $this->active();
        $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'], $this->accountant)->assertCreated();

        $this->assertSame(0, $this->inTenant(fn () => Journal::query()->count()));
        $this->assertSame(2, $this->inTenant(fn () => Posting::query()->count()));
        $this->api('GET', "assets/{$asset['id']}")->assertJsonPath('data.depreciated_to', '2026-01-31');
        $this->assertFalse($this->inTenant(fn () => app(ModuleRegistry::class)->isActive('accounting')));
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()));
    }
}
