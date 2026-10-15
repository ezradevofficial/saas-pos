<?php

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Support\PayFields;

/**
 * PAY-06: an employee's recurring or one-off item; its amount only when
 * the reader's field rules do not hide amounts (PAY-23).
 *
 * @property EmployeeItem $resource
 */
class EmployeeItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'payroll_employee_id' => $i->payroll_employee_id,
            'item' => $i->relationLoaded('item') && $i->item !== null ? ['id' => $i->item->id, 'code' => $i->item->code, 'name' => $i->item->name, 'kind' => $i->item->kind] : null,
            ...(app(PayFields::class)->hides(PayFields::AMOUNTS, $request) ? [] : ['amount' => Refs::money($i->amount_minor, $i->currency)]),
            'currency' => $i->currency,
            'recurrence' => $i->recurrence,
            'from_date' => $i->from_date?->toDateString(),
            'to_date' => $i->to_date?->toDateString(),
            'period_date' => $i->period_date?->toDateString(),
            'consumed_run_id' => $i->consumed_run_id,
            'note' => $i->note,
            'archived_at' => $i->archived_at?->toIso8601String(),
        ];
    }
}
