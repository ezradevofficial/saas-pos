<?php

namespace Modules\Procurement\Tenders;

use App\Core\Identity\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\BidScore;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderCriterion;
use Modules\Procurement\Models\TenderMember;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-11: scored technical and financial evaluation, in basis points
 * (0 to 10000 = 0 to 100.00), integer arithmetic rounded half up once per
 * score.
 *
 * - score(): a committee evaluator scores opened bids on the tender's
 *   criteria (0 to each criterion's maximum, a comment); scoring again
 *   replaces their score. Allowed until the award.
 * - rank(): per bid, an evaluator's technical score is Σ (score ÷ max ×
 *   weight) over the criteria (out of 100); the bid's technical score is the
 *   mean over every committee evaluator, the same set for every bid, and
 *   exists only once each of them scored each criterion. A bid under the
 *   pass mark is disqualified. Financial = lowest passing base total ÷ the
 *   bid's base total × 100. Combined = technical × technical weight +
 *   financial × financial weight (÷ 100). Ranked by combined score (then
 *   the lower price, then the earlier bid). The tender is `evaluated` only
 *   when every committee evaluator has scored every criterion of every
 *   opened bid (or the tender scores price only).
 */
class Evaluation
{
    public const FULL = 10000;

    /** @param list<array{bid_id: string, criterion_id: string, score: string|int|float, comment?: ?string}> $scores */
    public function score(Tender $tender, array $scores, User $by): Tender
    {
        return Tx::run(function () use ($tender, $scores, $by) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();

            if (! in_array($tender->status, [Tender::OPENED, Tender::EVALUATED], true)) {
                throw ProcurementError::make('tender_not_opened', status: 409);
            }

            if (! TenderMember::query()->where('tender_id', $tender->id)->where('user_id', $by->id)->where('role', TenderMember::EVALUATOR)->exists()) {
                throw ProcurementError::make('evaluator_not_member', status: 403);
            }

            $bids = Bid::query()->where('tender_id', $tender->id)->where('status', Bid::OPENED)->pluck('id')->all();
            $criteria = TenderCriterion::query()->where('tender_id', $tender->id)->get()->keyBy('id');

            foreach (array_values($scores) as $index => $input) {
                $criterion = $criteria->get($input['criterion_id']);

                if (! in_array($input['bid_id'], $bids, true) || $criterion === null) {
                    throw ProcurementError::make('line_unknown', field: "scores.{$index}");
                }

                $score = BigDecimal::of((string) $input['score']);

                if ($score->isNegative() || $score->isGreaterThan($criterion->max_score)) {
                    throw ProcurementError::make('score_out_of_range', ['max' => $criterion->max_score], "scores.{$index}.score");
                }

                $row = BidScore::query()->firstOrNew(['bid_id' => $input['bid_id'], 'criterion_id' => $criterion->id, 'evaluator_id' => $by->id]);
                $row->fill(['score' => (string) $score->toScale(4, RoundingMode::HalfUp), 'comment' => $input['comment'] ?? null])->save();
            }

            $this->rank($tender);

            return $tender->refresh();
        });
    }

    /** Recompute every opened bid's scores, outcome and rank (inside the caller's transaction). */
    public function rank(Tender $tender): void
    {
        $criteria = TenderCriterion::query()->where('tender_id', $tender->id)->get();
        $bids = Bid::query()->where('tender_id', $tender->id)->where('status', Bid::OPENED)->orderBy('submitted_at')->get();
        $scores = BidScore::query()->whereIn('bid_id', $bids->pluck('id'))->get()->groupBy('bid_id');
        $priceOnly = $tender->technical_weight === 0 || $criteria->isEmpty();
        $evaluators = TenderMember::query()->where('tender_id', $tender->id)->where('role', TenderMember::EVALUATOR)->pluck('user_id')->all();
        $technical = [];

        foreach ($bids as $bid) {
            $technical[$bid->id] = $priceOnly ? self::FULL : self::technical($criteria, $scores->get($bid->id, collect()), $evaluators);
        }

        $passMark = BigDecimal::of((string) $tender->pass_mark)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        $passing = $bids->filter(fn (Bid $bid) => $technical[$bid->id] !== null && $technical[$bid->id] >= $passMark && $bid->complete && $bid->base_total_minor > 0);
        $lowest = $passing->min('base_total_minor');
        $ranked = [];

        foreach ($bids as $bid) {
            $tech = $technical[$bid->id];
            $passes = $passing->contains('id', $bid->id);
            $financial = $passes ? (int) (string) BigDecimal::of($lowest)->multipliedBy(self::FULL)->dividedBy($bid->base_total_minor, 0, RoundingMode::HalfUp) : null;
            $combined = $passes ? (int) (string) BigDecimal::of($tech)->multipliedBy($tender->technical_weight)
                ->plus(BigDecimal::of($financial)->multipliedBy($tender->financial_weight))->dividedBy(100, 0, RoundingMode::HalfUp) : null;
            $bid->fill([
                'technical_score_bp' => $tech,
                'financial_score_bp' => $financial,
                'combined_score_bp' => $combined,
                'outcome' => $bid->outcome === Bid::AWARDED || $bid->outcome === Bid::REGRETTED ? $bid->outcome : ($tech === null || $passes ? null : Bid::DISQUALIFIED),
            ]);

            if ($passes) {
                $ranked[] = $bid;
            }
        }

        usort($ranked, fn (Bid $a, Bid $b) => [$b->combined_score_bp, $a->base_total_minor, $a->submitted_at] <=> [$a->combined_score_bp, $b->base_total_minor, $b->submitted_at]);

        foreach ($bids as $bid) {
            $position = array_search($bid, $ranked, true);
            $bid->fill(['rank' => $position === false ? null : $position + 1])->save();
        }

        $complete = $bids->isNotEmpty() && $bids->every(fn (Bid $bid) => $technical[$bid->id] !== null);

        if ($tender->status === Tender::OPENED && $complete) {
            $tender->fill(['status' => Tender::EVALUATED, 'evaluated_at' => CarbonImmutable::now()])->save();
        }
    }

    /**
     * A bid's technical score in basis points: the mean over $evaluators of
     * Σ score ÷ max × weight; null until every one of them scored every
     * criterion (no evaluator: null).
     *
     * @param  Collection<int, TenderCriterion>  $criteria
     * @param  Collection<int, BidScore>  $scores
     * @param  list<string>  $evaluators
     */
    public static function technical(Collection $criteria, Collection $scores, array $evaluators): ?int
    {
        if ($evaluators === []) {
            return null;
        }

        $sum = BigDecimal::zero();

        foreach ($evaluators as $evaluator) {
            $rows = $scores->where('evaluator_id', $evaluator);
            $points = BigDecimal::zero();

            foreach ($criteria as $criterion) {
                $score = $rows->firstWhere('criterion_id', $criterion->id);

                if ($score === null) {
                    return null;
                }

                // score ÷ max × weight × 100 basis points per point of weight.
                $points = $points->plus(BigDecimal::of((string) $score->score)->multipliedBy($criterion->weight)->multipliedBy(100)->dividedBy($criterion->max_score, 8, RoundingMode::HalfUp));
            }

            $sum = $sum->plus($points);
        }

        return $sum->dividedBy(count($evaluators), 0, RoundingMode::HalfUp)->toInt();
    }
}
