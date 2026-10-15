<?php

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\Payslip;

/**
 * PAY-19: a payslip; in the detail, its run line (only Payroll's
 * controllers build it, after checking the reader sees the payslip).
 *
 * @property Payslip $resource
 */
class PayslipResource extends JsonResource
{
    private bool $detail = false;

    private bool $own = false;

    public function detail(bool $own = false): static
    {
        $this->detail = true;
        $this->own = $own;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $run = $p->relationLoaded('run') ? $p->run : null;
        $line = $p->relationLoaded('line') ? $p->line : null;

        return [
            'id' => $p->id,
            'number' => $p->number,
            'run' => $run === null ? null : ['id' => $run->id, 'number' => $run->number, 'kind' => $run->kind, 'period_start' => $run->period_start->toDateString(), 'period_end' => $run->period_end->toDateString(), 'pay_date' => $run->pay_date->toDateString()],
            'employee' => $line === null ? null : ['id' => $p->payroll_employee_id, 'number' => $line->number, 'name' => $line->name],
            'issued_at' => $p->issued_at->toIso8601String(),
            ...($this->detail && $line !== null ? ['line' => RunLineResource::make($line)->own($this->own)->toArray($request)] : []),
            ...(! $this->detail && $line !== null ? ['net' => Refs::money($line->net_minor, $line->pay_currency), 'gross' => Refs::money($line->gross_minor, $line->pay_currency)] : []),
        ];
    }
}
