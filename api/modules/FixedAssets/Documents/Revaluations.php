<?php

namespace Modules\FixedAssets\Documents;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Categories\Categories;
use Modules\FixedAssets\Depreciation\Calculator;
use Modules\FixedAssets\Depreciation\Spans;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Revaluation;
use Modules\FixedAssets\Postings\Postings;
use Modules\FixedAssets\Support\ClosedBooks;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;
use Modules\FixedAssets\Support\RunLock;

/**
 * FA-15, FA-16: revaluations (elimination method, decision 6) and
 * impairments of active assets, dated no earlier than their last charge
 * (`period_depreciated`) and in an open period. Each posts one ledger
 * source (FA-22) that balances by construction:
 *
 * - revaluation to V: accumulated depreciation and impairment (A + I) are
 *   eliminated, cost becomes V; the change V − book value first reverses
 *   this asset's earlier revaluation losses (`gain`), the rest goes to its
 *   surplus; a decrease first uses its surplus, the rest is a `loss`.
 * - impairment to a recoverable amount R below the book value: the loss
 *   is added to impairment, first against the surplus, the rest in profit
 *   or loss, and that part is remembered as a revaluation loss, so a later
 *   upward revaluation reverses it through profit or loss first.
 *
 * Both first charge the depreciation not yet charged up to the date (by
 * days; under `mid_month` up to the end of the month before), posted as its
 * own depreciation source, the way a disposal does; both hold the company's
 * run lock (review #3, #4, #10).
 */
class Revaluations
{
    public const KINDS = ['revaluation', 'impairment'];

    public function __construct(
        private readonly ClosedBooks $books,
        private readonly Postings $postings,
        private readonly History $history,
        private readonly Spans $spans,
    ) {}

    /** @param array{kind: string, revalued_on: string, value_minor: int|string, reason: string} $data */
    public function post(Asset $asset, array $data, User $by): Revaluation
    {
        return FaError::transaction(function () use ($asset, $data, $by) {
            RunLock::take($asset->company_id);
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::parse($data['revalued_on']);

            if ($asset->status !== Asset::ACTIVE) {
                throw FaError::make('asset_not_active', status: 409);
            }

            if (($asset->depreciated_to !== null && $date < $asset->depreciated_to) || $date < $asset->in_service_on) {
                throw FaError::make('period_depreciated', ['date' => ($asset->depreciated_to ?? $asset->in_service_on)->toDateString()], 'revalued_on');
            }

            $this->books->assertOpen($asset->company_id, $date, 'revalued_on');
            $id = (new Revaluation)->newUniqueId();
            $this->catchUp($asset, $date, $id);
            $value = (int) $data['value_minor'];
            $before = History::book($asset);
            $cost = (int) $asset->book_cost_minor;
            $accumulated = (int) $asset->accumulated_minor;
            $impairment = (int) $asset->impairment_minor;
            $surplus = (int) $asset->surplus_minor;
            $losses = (int) $asset->revaluation_loss_minor;
            $bookValue = $cost - $accumulated - $impairment;
            $category = $asset->category_id;

            if ($data['kind'] === 'impairment') {
                if ($value >= $bookValue) {
                    throw FaError::make('value_not_lower', field: 'value_minor');
                }

                $loss = $bookValue - $value;
                $used = min($loss, $surplus);
                $pnl = $loss - $used;
                $asset->fill(['impairment_minor' => $impairment + $loss, 'surplus_minor' => $surplus - $used, 'revaluation_loss_minor' => $losses + $pnl])->save();
                $amounts = ['impairment' => $pnl, 'surplus' => $used, "accumulated.{$category}" => $loss];
                [$surplusChange, $pnlChange, $source] = [-$used, -$pnl, Postings::IMPAIRMENT];
            } else {
                $change = $value - $bookValue;

                if ($change >= 0) {
                    $gain = min($change, $losses);
                    $toSurplus = $change - $gain;
                    [$surplusChange, $pnlChange, $loss] = [$toSurplus, $gain, 0];
                    $losses -= $gain;
                } else {
                    $used = min(-$change, $surplus);
                    $loss = -$change - $used;
                    [$surplusChange, $pnlChange, $gain] = [-$used, -$loss, 0];
                    $losses += $loss;
                }

                $asset->fill([
                    'book_cost_minor' => $value,
                    'accumulated_minor' => 0,
                    'impairment_minor' => 0,
                    'surplus_minor' => $surplus + $surplusChange,
                    'revaluation_loss_minor' => $losses,
                    'residual_minor' => min((int) $asset->residual_minor, $value),
                ])->save();
                $amounts = [
                    "accumulated.{$category}" => $accumulated + $impairment,
                    "cost.{$category}" => $value - $cost,
                    'surplus' => $surplusChange,
                    'gain' => $gain,
                    'loss' => $loss,
                ];
                $source = Postings::REVALUATION;
            }

            $revaluation = Revaluation::query()->create([
                'id' => $id,
                'company_id' => $asset->company_id,
                'asset_id' => $asset->id,
                'kind' => $data['kind'],
                'revalued_on' => $date->toDateString(),
                'value_minor' => $value,
                'before' => $before,
                'after' => History::book($asset),
                'surplus_change_minor' => $surplusChange,
                'pnl_change_minor' => $pnlChange,
                'reason' => $data['reason'],
                'posted_by' => $by->id,
            ]);

            $this->history->record($asset, $data['kind'] === 'impairment' ? 'impaired' : 'revalued', $date, $before, History::book($asset), 'revaluation', $revaluation->id, $data['reason'], $by);
            $this->postings->raise($source, 'asset', $asset->id, $asset->company_id, $asset->branch_id, $asset->cost_centre_id, null, $date,
                (string) $asset->base_currency, $amounts, $asset->number, null);

            return $revaluation;
        });
    }

    /** Review #4: depreciation up to $date (under `mid_month`, to the end of the month before), charged and posted first. */
    private function catchUp(Asset $asset, CarbonImmutable $date, string $id): void
    {
        $convention = (string) Categories::settings($asset->company_id)->convention;
        $to = $convention === Calculator::MID_MONTH ? $date->startOfMonth()->subDay() : $date;
        $span = $this->spans->next($asset, $to, $convention);

        if ($span === null || $span['charge']->amount <= 0) {
            return;
        }

        $charge = $span['charge']->amount;
        $asset->fill(['accumulated_minor' => (int) $asset->accumulated_minor + $charge, 'depreciated_to' => $to->toDateString()])->save();
        $this->postings->raise(Postings::DEPRECIATION, 'revaluation', $id, $asset->company_id, $asset->branch_id, $asset->cost_centre_id, null, $to,
            (string) $asset->base_currency, ["depreciation.{$asset->category_id}" => $charge, "accumulated.{$asset->category_id}" => $charge], $asset->number, null);
    }
}
