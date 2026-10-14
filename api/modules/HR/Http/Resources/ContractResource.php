<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\Contract;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Sensitive;

/** HR-07: a contract; pay only with `hr.pay.view` at the employee's place (HR-09), audited when shown (HR-11). */
class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Contract $contract */
        $contract = $this->resource;
        $employee = $contract->relationLoaded('employee') ? $contract->employee : Employee::query()->find($contract->employee_id);
        $sensitive = app(Sensitive::class);
        $pay = $employee !== null && $request->user() !== null && $sensitive->sees($request->user(), $employee, Sensitive::PAY, $request);

        if ($pay) {
            $sensitive->recordView($employee, [Sensitive::PAY], 'contract');
        }

        return [
            'id' => $contract->id,
            'employee' => Refs::employee($employee),
            'type' => $contract->type,
            'start_date' => Refs::date($contract->start_date),
            'end_date' => Refs::date($contract->end_date),
            'probation_end_date' => Refs::date($contract->probation_end_date),
            'position' => $contract->position === null ? null : ['id' => $contract->position->id, 'code' => $contract->position->code, 'title' => $contract->position->title],
            'department' => Refs::coded($contract->department),
            'pay_basis' => $contract->pay_basis,
            'pay' => $pay ? Refs::money($contract->pay_minor, $contract->currency) : null,
            'pay_visible' => $pay,
            'hours_per_week' => $contract->hours_per_week === null ? null : (string) $contract->hours_per_week,
            'status' => $contract->status,
            'end_reason' => $contract->end_reason,
            'created_at' => Refs::at($contract->created_at),
        ];
    }
}
