<?php

namespace Modules\Payroll\Http\Resources;

use App\Core\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Statutory\MissingValues;
use Modules\Payroll\Support\PayFields;

/**
 * PAY-14 to PAY-18: a run, its totals (when the reader's field rules do
 * not hide them, PAY-23), what the pack lacks (PAY-08), who prepared and
 * submitted it, and its correction link.
 *
 * @property Run $resource
 */
class RunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $run = $this->resource;
        $users = User::query()->whereIn('id', array_filter([$run->calculated_by, $run->submitted_by, $run->posted_by]))->pluck('name', 'id');
        $user = fn (?string $id) => $id === null ? null : ['id' => $id, 'name' => $users[$id] ?? null];
        $totals = $run->totals;

        return [
            'id' => $run->id,
            'number' => $run->number,
            'company' => Refs::named($run->relationLoaded('company') ? $run->company : null),
            'company_id' => $run->company_id,
            'pay_group' => Refs::named($run->relationLoaded('payGroup') ? $run->payGroup : null),
            'kind' => $run->kind,
            'corrects_run_id' => $run->corrects_run_id,
            'correction_mode' => $run->correction_mode,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'pay_date' => $run->pay_date->toDateString(),
            'status' => $run->status,
            'employees' => $totals['employees'] ?? 0,
            'lines_with_errors' => $totals['errors'] ?? 0,
            ...(app(PayFields::class)->hides(PayFields::TOTALS, $request) ? [] : ['totals' => self::totals($totals)]),
            'missing' => $run->missing === null ? null : [
                'items' => $run->missing,
                'message' => MissingValues::describe($run->missing),
            ],
            // Review fix 5: the statutory pack the run was calculated from.
            'pack' => $run->inputs['pack'] ?? null,
            // CP-01: researched pack values the run used (the web app shows the notice), components left out
            // while unconfirmed and those the company marked not applicable.
            'statutory' => [
                'researched' => $run->inputs['statutory']['researched'] ?? [],
                'not_applied' => $run->inputs['statutory']['not_applied'] ?? [],
                'not_applicable' => $run->inputs['statutory']['not_applicable'] ?? [],
            ],
            'uses_researched_values' => ($run->inputs['statutory']['researched'] ?? []) !== [],
            // Decisions 29, 33, 40: open attendance periods, late HR changes since the last run, employees without a pay group.
            'review' => [
                'attendance_open' => $run->inputs['review']['attendance_open'] ?? [],
                'late_changes' => $run->inputs['review']['late_changes'] ?? [],
                'no_pay_group' => $run->inputs['review']['no_pay_group'] ?? [],
            ],
            'note' => $run->note,
            'prepared_by' => $user($run->calculated_by),
            'calculated_at' => $run->calculated_at?->toIso8601String(),
            'submitted_by' => $user($run->submitted_by),
            'submitted_at' => $run->submitted_at?->toIso8601String(),
            'approved_at' => $run->approved_at?->toIso8601String(),
            'rejected_at' => $run->rejected_at?->toIso8601String(),
            'posted_by' => $user($run->posted_by),
            'posted_at' => $run->posted_at?->toIso8601String(),
            'cancelled_at' => $run->cancelled_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /** Totals per pay currency, in base and the previous run's, as money. */
    private static function totals(?array $totals): ?array
    {
        if ($totals === null) {
            return null;
        }

        $currencies = [];

        foreach ($totals['currencies'] ?? [] as $currency => $row) {
            $previous = $totals['previous']['currencies'][$currency] ?? null;
            $currencies[] = [
                'currency' => $currency,
                'employees' => $row['employees'],
                'gross' => Refs::money($row['gross_minor'], $currency),
                'statutory' => Refs::money($row['statutory_minor'], $currency),
                'deductions' => Refs::money($row['deductions_minor'], $currency),
                'loans' => Refs::money($row['loans_minor'], $currency),
                'net' => Refs::money($row['net_minor'], $currency),
                'employer' => Refs::money($row['employer_minor'], $currency),
                'previous' => $previous === null ? null : [
                    'employees' => $previous['employees'],
                    'gross' => Refs::money($previous['gross_minor'], $currency),
                    'net' => Refs::money($previous['net_minor'], $currency),
                ],
            ];
        }

        $base = $totals['base'] ?? null;

        return [
            'currencies' => $currencies,
            'base' => $base === null || $base['currency'] === null ? null : [
                'gross' => Refs::money($base['gross_minor'], $base['currency']),
                'net' => Refs::money($base['net_minor'], $base['currency']),
                'employer' => Refs::money($base['employer_minor'], $base['currency']),
            ],
            'previous_run_id' => $totals['previous']['run_id'] ?? null,
            'gone' => $totals['previous']['gone'] ?? [],
            // Decision 36: what an approver without payroll.pay.view judges the run by.
            'departments' => array_map(fn (array $d) => [
                'department' => $d['department_id'] === null ? null : ['id' => $d['department_id'], 'name' => $d['name']],
                'name' => $d['name'],
                'currency' => $d['currency'],
                'employees' => $d['employees'],
                'gross' => Refs::money($d['gross_minor'], $d['currency']),
                'net' => Refs::money($d['net_minor'], $d['currency']),
                'employer' => Refs::money($d['employer_minor'], $d['currency']),
            ], $totals['departments'] ?? []),
            'variance' => $totals['variance'] ?? null,
        ];
    }
}
