<?php

namespace Modules\HR\Records;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\HR\Models\Appraisal;
use Modules\HR\Models\AppraisalCycle;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Errors;

/**
 * HR-30: appraisal cycles and appraisals. Opening a cycle creates one
 * appraisal per active employee hired by its end, reviewed by the line
 * manager's user. The overall rating is the weighted mean of the goals'
 * ratings (weights default to 1), half up to 2 decimals, else the
 * manager's rating.
 */
class Appraisals
{
    public function open(AppraisalCycle $cycle): AppraisalCycle
    {
        return Errors::transaction(function () use ($cycle) {
            $cycle = AppraisalCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();

            if ($cycle->status !== 'draft') {
                throw Errors::make(409, 'document_not_open');
            }

            $employees = Employee::query()->where('company_id', $cycle->company_id)->where('status', Employee::ACTIVE)
                ->where('hire_date', '<=', $cycle->period_end->toDateString())->get(['id', 'reports_to_id']);
            $managers = Employee::query()->whereKey($employees->pluck('reports_to_id')->filter()->unique()->all())->pluck('user_id', 'id');
            $own = Employee::query()->whereKey($employees->pluck('id')->all())->pluck('user_id', 'id');

            foreach ($employees as $employee) {
                Appraisal::query()->firstOrCreate(
                    ['cycle_id' => $cycle->id, 'employee_id' => $employee->id],
                    ['company_id' => $cycle->company_id, 'reviewer_id' => self::reviewer($managers[$employee->reports_to_id] ?? null, $own[$employee->id] ?? null), 'goals' => [], 'status' => 'self_review'],
                );
            }

            $cycle->fill(['status' => 'open'])->save();

            return $cycle->refresh();
        });
    }

    /** The line manager's user, never the employee's own. */
    private static function reviewer(?string $manager, ?string $own): ?string
    {
        return $manager !== null && $manager !== $own ? $manager : null;
    }

    public function close(AppraisalCycle $cycle): AppraisalCycle
    {
        if ($cycle->status !== 'open') {
            throw Errors::make(409, 'document_not_open');
        }

        $cycle->fill(['status' => 'closed'])->save();

        return $cycle->refresh();
    }

    /** The employee's self review: their rating and comments; moves the appraisal to the manager. */
    public function selfReview(Appraisal $appraisal, array $data): Appraisal
    {
        return $this->change($appraisal, ['self_review'], function (Appraisal $appraisal) use ($data) {
            $appraisal->fill(array_intersect_key($data, array_flip(['self_rating', 'self_comments'])));

            if (($data['submit'] ?? false) === true) {
                $appraisal->status = 'manager_review';
            }
        });
    }

    /** The reviewer's (or HR's) review: goals, ratings and comments. */
    public function review(Appraisal $appraisal, array $data): Appraisal
    {
        return $this->change($appraisal, ['self_review', 'manager_review'], function (Appraisal $appraisal) use ($data) {
            $fields = array_intersect_key($data, array_flip(['manager_rating', 'manager_comments', 'reviewer_id']));

            if (array_key_exists('goals', $data)) {
                $fields['goals'] = array_map(fn (array $goal) => [
                    'title' => $goal['title'], 'weight' => isset($goal['weight']) ? (string) $goal['weight'] : '1',
                    'rating' => $goal['rating'] ?? null, 'comment' => $goal['comment'] ?? null,
                ], array_values($data['goals']));
            }

            $appraisal->fill($fields);
            $appraisal->overall_rating = self::overall($appraisal->goals ?? [], $appraisal->manager_rating);
        });
    }

    public function complete(Appraisal $appraisal): Appraisal
    {
        return $this->change($appraisal, ['manager_review', 'self_review'], function (Appraisal $appraisal) {
            if ($appraisal->manager_rating === null && $appraisal->overall_rating === null) {
                throw Errors::make(422, 'rating_required', field: 'manager_rating');
            }

            $appraisal->fill(['status' => 'completed', 'completed_at' => CarbonImmutable::now()]);
        });
    }

    public function acknowledge(Appraisal $appraisal): Appraisal
    {
        return $this->change($appraisal, ['completed'], fn (Appraisal $appraisal) => $appraisal->fill(['status' => 'acknowledged', 'acknowledged_at' => CarbonImmutable::now()]));
    }

    /** @param list<array{weight?: string, rating?: ?int}> $goals */
    public static function overall(array $goals, ?int $managerRating): ?string
    {
        $sum = BigDecimal::zero();
        $weights = BigDecimal::zero();

        foreach ($goals as $goal) {
            if (($goal['rating'] ?? null) === null) {
                continue;
            }

            $weight = BigDecimal::of((string) ($goal['weight'] ?? '1'));
            $sum = $sum->plus($weight->multipliedBy((int) $goal['rating']));
            $weights = $weights->plus($weight);
        }

        if ($weights->isZero()) {
            return $managerRating === null ? null : number_format($managerRating, 2, '.', '');
        }

        return (string) $sum->dividedBy($weights, 2, RoundingMode::HalfUp);
    }

    /** @param list<string> $statuses */
    private function change(Appraisal $appraisal, array $statuses, callable $fn): Appraisal
    {
        return Errors::transaction(function () use ($appraisal, $statuses, $fn) {
            $appraisal = Appraisal::query()->whereKey($appraisal->id)->lockForUpdate()->firstOrFail();
            $cycle = AppraisalCycle::query()->findOrFail($appraisal->cycle_id);

            if (! in_array($appraisal->status, $statuses, true) || ($cycle->status !== 'open' && $appraisal->status !== 'completed')) {
                throw Errors::make(409, 'document_not_open');
            }

            $fn($appraisal);
            $appraisal->save();

            return $appraisal->refresh();
        });
    }
}
