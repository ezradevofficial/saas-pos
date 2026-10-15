<?php

namespace Modules\HR\Http\Requests;

use Carbon\CarbonImmutable;

/** POST hr/timesheets/recompute `{company_id, from, to}` (HR-25): `hr.attendance.manage` at the company; at most 92 days. */
class RecomputeTimesheetsRequest extends CompanyActionRequest
{
    protected function permission(): string
    {
        return 'hr.attendance.manage';
    }

    protected function fields(): array
    {
        return ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$this->date92()]];
    }

    private function date92(): string
    {
        $from = $this->input('from');

        return is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? CarbonImmutable::parse($from)->addDays(91)->toDateString() : '2999-12-31';
    }
}
