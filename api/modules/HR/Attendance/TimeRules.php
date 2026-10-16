<?php

namespace Modules\HR\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Modules\HR\Models\TimeRule;
use Modules\HR\Support\Errors;

/**
 * HR-20: a company's time rules, effective-dated and never overlapping (the
 * database refuses an overlap). Saving one recomputes the timesheet days of
 * its dates (before and after a change) that are not approved and not in a
 * closed period (HR-25, decision 29).
 */
class TimeRules
{
    public function __construct(private readonly Timesheets $timesheets) {}

    public const FIELDS = ['effective_from', 'effective_to', 'daily_overtime_after_minutes', 'weekly_overtime_after_minutes', 'night_start', 'night_end', 'late_grace_minutes', 'early_leave_grace_minutes', 'rest_days'];

    /** @param array<string, mixed> $data validated */
    public function create(array $data): TimeRule
    {
        $rule = $this->saving(fn () => TimeRule::create([...array_intersect_key($data, array_flip(self::FIELDS)), 'company_id' => $data['company_id'], 'rest_days' => array_values(array_unique($data['rest_days'] ?? []))]));
        $this->recompute($rule->company_id, [[$rule->effective_from, $rule->effective_to]]);

        return $rule;
    }

    /** @param array<string, mixed> $data validated */
    public function update(TimeRule $rule, array $data): TimeRule
    {
        $before = [$rule->effective_from, $rule->effective_to];
        $rule = $this->saving(function () use ($rule, $data) {
            $fields = array_intersect_key($data, array_flip(self::FIELDS));

            if (isset($fields['rest_days'])) {
                $fields['rest_days'] = array_values(array_unique($fields['rest_days']));
            }

            $rule->fill($fields)->save();

            return $rule->refresh();
        });
        $this->recompute($rule->company_id, [$before, [$rule->effective_from, $rule->effective_to]]);

        return $rule;
    }

    /**
     * HR-25: recompute the open days of the rule's dates, up to today
     * (later days have no punches or started shifts yet).
     *
     * @param  list<array{0: CarbonImmutable, 1: ?CarbonImmutable}>  $ranges
     */
    private function recompute(string $companyId, array $ranges): void
    {
        $tomorrow = CarbonImmutable::now()->addDay()->startOfDay();
        $from = min(array_map(fn (array $range) => $range[0], $ranges));
        $to = max(array_map(fn (array $range) => $range[1] === null || $range[1]->greaterThan($tomorrow) ? $tomorrow : $range[1], $ranges));

        if ($from->lessThanOrEqualTo($to)) {
            $this->timesheets->recomputeRange($companyId, $from->toDateString(), $to->toDateString());
        }
    }

    private function saving(callable $fn): TimeRule
    {
        try {
            return Errors::transaction($fn);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw Errors::make(422, 'time_rule_overlap', field: 'effective_from');
            }

            if (($e->errorInfo[0] ?? null) === '23514') {
                throw Errors::make(422, 'time_rule_invalid', field: 'effective_to');
            }

            throw $e;
        }
    }
}
