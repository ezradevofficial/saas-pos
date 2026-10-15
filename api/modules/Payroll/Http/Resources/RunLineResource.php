<?php

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Support\PayFields;

/**
 * PAY-09 to PAY-15: one employee's line in a run. Every amount (and the
 * rates and inputs that would let one work them out) is left out when the
 * reader's field rules hide `amounts` (PAY-23); errors and warnings are
 * always shown.
 *
 * @property RunLine $resource
 */
class RunLineResource extends JsonResource
{
    public const AMOUNT_KEYS = ['basic', 'gross', 'statutory', 'deductions', 'loans', 'net', 'employer', 'gross_base', 'net_base', 'taxable_pay', 'earnings', 'deduction_lines', 'statutory_lines', 'loan_lines', 'variance', 'fx', 'inputs'];

    private bool $own = false;

    /** PAY-24: the employee's own line (their payslip): never hidden from them. */
    public function own(bool $own = true): static
    {
        $this->own = $own;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $l = $this->resource;
        $currency = $l->pay_currency;
        $data = [
            'id' => $l->id,
            'run_id' => $l->run_id,
            'payroll_employee_id' => $l->payroll_employee_id,
            'employee' => ['id' => $l->payroll_employee_id, 'number' => $l->number, 'name' => $l->name],
            'department' => Refs::named($l->relationLoaded('department') ? $l->getRelation('department') : null),
            'department_id' => $l->department_id,
            'branch_id' => $l->branch_id,
            'pay_currency' => $currency,
            'statutory_currency' => $l->statutory_currency,
            'errors' => array_map(fn (string $code) => ['code' => $code, 'message' => __('payroll.line_errors.'.$code)], $l->errors),
            'warnings' => array_map(fn (string $code) => ['code' => $code, 'message' => __('payroll.line_warnings.'.$code)], $l->warnings),
        ];

        if (! $this->own && app(PayFields::class)->hides(PayFields::AMOUNTS, $request)) {
            return $data;
        }

        return [
            ...$data,
            'basic' => Refs::money($l->basic_minor, $currency),
            'gross' => Refs::money($l->gross_minor, $currency),
            'statutory' => Refs::money($l->statutory_minor, $currency),
            'deductions' => Refs::money($l->deductions_minor, $currency),
            'loans' => Refs::money($l->loans_minor, $currency),
            'net' => Refs::money($l->net_minor, $currency),
            'employer' => Refs::money($l->employer_minor, $currency),
            'gross_base' => Refs::money($l->gross_base_minor, $l->base_currency),
            'net_base' => Refs::money($l->net_base_minor, $l->base_currency),
            'taxable_pay' => Refs::money($l->taxable_statutory_minor, $l->statutory_currency),
            'earnings' => Refs::rows($l->earnings, $currency, ['amount_minor']),
            'deduction_lines' => Refs::rows($l->deductions, $currency, ['amount_minor']),
            'statutory_lines' => array_map(fn (array $c) => [
                'code' => $c['code'], 'kind' => $c['kind'], 'name' => $c['name'],
                'employee' => Refs::money($c['employee_minor'], $currency),
                'employer' => Refs::money($c['employer_minor'], $currency),
                'employee_statutory' => Refs::money($c['employee_statutory_minor'], $l->statutory_currency),
                'employer_statutory' => Refs::money($c['employer_statutory_minor'], $l->statutory_currency),
                'base_statutory' => Refs::money($c['base_statutory_minor'] ?? '0', $l->statutory_currency),
            ], $l->statutory),
            'loan_lines' => Refs::rows($l->loans, $currency, ['amount_minor', 'balance_before_minor']),
            'variance' => $l->variance === null ? null : [
                'status' => $l->variance['status'],
                ...(isset($l->variance['gross_diff_minor']) ? [
                    'previous_gross' => Refs::money($l->variance['previous_gross_minor'], $currency),
                    'previous_net' => Refs::money($l->variance['previous_net_minor'], $currency),
                    'gross_diff' => Refs::money($l->variance['gross_diff_minor'], $currency),
                    'net_diff' => Refs::money($l->variance['net_diff_minor'], $currency),
                    'gross_pct' => $l->variance['gross_pct'],
                    'net_pct' => $l->variance['net_pct'],
                ] : []),
            ],
            'fx' => array_values($l->fx ?? []),
            'inputs' => array_diff_key($l->inputs, array_flip(['item_ids', 'attendance_ids'])),
        ];
    }
}
