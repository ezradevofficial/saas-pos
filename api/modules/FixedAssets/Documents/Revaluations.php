<?php

namespace Modules\FixedAssets\Documents;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Revaluation;
use Modules\FixedAssets\Postings\Postings;
use Modules\FixedAssets\Support\ClosedBooks;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;

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
 *   or loss.
 */
class Revaluations
{
    public const KINDS = ['revaluation', 'impairment'];

    public function __construct(
        private readonly ClosedBooks $books,
        private readonly Postings $postings,
        private readonly History $history,
    ) {}

    /** @param array{kind: string, revalued_on: string, value_minor: int|string, reason: string} $data */
    public function post(Asset $asset, array $data, User $by): Revaluation
    {
        return FaError::transaction(function () use ($asset, $data, $by) {
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::parse($data['revalued_on']);

            if ($asset->status !== Asset::ACTIVE) {
                throw FaError::make('asset_not_active', status: 409);
            }

            if (($asset->depreciated_to !== null && $date < $asset->depreciated_to) || $date < $asset->in_service_on) {
                throw FaError::make('period_depreciated', ['date' => ($asset->depreciated_to ?? $asset->in_service_on)->toDateString()], 'revalued_on');
            }

            $this->books->assertOpen($asset->company_id, $date, 'revalued_on');
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
                $asset->fill(['impairment_minor' => $impairment + $loss, 'surplus_minor' => $surplus - $used])->save();
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
}
