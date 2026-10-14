<?php

namespace Modules\HR\Attendance;

use Illuminate\Database\QueryException;
use Modules\HR\Models\TimeRule;
use Modules\HR\Support\Errors;

/** HR-20: a company's time rules, effective-dated and never overlapping (the database refuses an overlap). */
class TimeRules
{
    public const FIELDS = ['effective_from', 'effective_to', 'daily_overtime_after_minutes', 'weekly_overtime_after_minutes', 'night_start', 'night_end', 'late_grace_minutes', 'early_leave_grace_minutes', 'rest_days'];

    /** @param array<string, mixed> $data validated */
    public function create(array $data): TimeRule
    {
        return $this->saving(fn () => TimeRule::create([...array_intersect_key($data, array_flip(self::FIELDS)), 'company_id' => $data['company_id'], 'rest_days' => array_values(array_unique($data['rest_days'] ?? []))]));
    }

    /** @param array<string, mixed> $data validated */
    public function update(TimeRule $rule, array $data): TimeRule
    {
        return $this->saving(function () use ($rule, $data) {
            $fields = array_intersect_key($data, array_flip(self::FIELDS));

            if (isset($fields['rest_days'])) {
                $fields['rest_days'] = array_values(array_unique($fields['rest_days']));
            }

            $rule->fill($fields)->save();

            return $rule->refresh();
        });
    }

    private function saving(callable $fn): TimeRule
    {
        try {
            return Errors::transaction($fn);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw Errors::make(422, 'time_rule_overlap', field: 'effective_from');
            }

            throw $e;
        }
    }
}
