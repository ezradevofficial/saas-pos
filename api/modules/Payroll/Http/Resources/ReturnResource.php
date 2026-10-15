<?php

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\StatutoryReturn;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Support\PayFields;

/**
 * PAY-21: a generated statutory return (its totals when the reader's
 * field rules do not hide totals).
 *
 * @property StatutoryReturn $resource
 */
class ReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $currency = $r->totals['currency'] ?? null;

        return [
            'id' => $r->id,
            'company_id' => $r->company_id,
            'type' => $r->type,
            'name' => PayrollPack::label('returns', $r->type),
            'period_start' => $r->period_start->toDateString(),
            'period_end' => $r->period_end->toDateString(),
            'runs' => count($r->run_ids),
            'employee_count' => $r->employee_count,
            'needs_confirmation' => $r->needs_confirmation,
            ...(app(PayFields::class)->hides(PayFields::TOTALS, $request) ? [] : ['totals' => array_map(fn (string $code, array $t) => [
                'code' => $code,
                'name' => PayrollPack::label('components', $code),
                'employee' => Refs::money($t['employee_minor'], $currency),
                'employer' => Refs::money($t['employer_minor'], $currency),
            ], array_keys($r->totals['components'] ?? []), array_values($r->totals['components'] ?? []))]),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
