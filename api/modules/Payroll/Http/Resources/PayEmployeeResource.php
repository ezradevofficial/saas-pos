<?php

namespace Modules\Payroll\Http\Resources;

use App\Core\MasterData\Items\Http\Resources\HidesFields;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollContract;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Support\PayFields;

/**
 * PAY-05: an employee's pay setup. The contract pay in force is shown in
 * the detail only, and only when the reader's field rules do not hide
 * amounts (PAY-23) and the reader sees HR's contract pay (decision 42).
 *
 * @property PayrollEmployee $resource
 */
class PayEmployeeResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $data = [
            'id' => $e->id,
            'employee_id' => $e->employee_id,
            'number' => $e->number,
            'name' => $e->name,
            'status' => $e->status,
            'company' => Refs::named($e->relationLoaded('company') ? $e->company : null),
            'branch' => Refs::named($e->relationLoaded('branch') ? $e->branch : null),
            'location' => Refs::named($e->relationLoaded('location') ? $e->location : null),
            'department' => Refs::named($e->relationLoaded('department') ? $e->department : null),
            'hire_date' => $e->hire_date->toDateString(),
            'termination_date' => $e->termination_date?->toDateString(),
            'pay_group' => Refs::named($e->relationLoaded('payGroup') ? $e->payGroup : null),
            'pay_currency' => $e->pay_currency,
            'payment_method' => $e->payment_method,
            'dependants' => $e->dependants,
            'children' => $e->children,
            'on_hold' => $e->on_hold,
            'synced_at' => $e->synced_at?->toIso8601String(),
        ];

        if ($this->detail && ! app(PayFields::class)->hides(PayFields::AMOUNTS, $request) && $this->seesContractPay($request)) {
            $today = CarbonImmutable::now()->toDateString();
            $contract = PayrollContract::query()->where('employee_id', $e->employee_id)->whereDate('start_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->orderByDesc('start_date')->first()
                ?? PayrollContract::query()->where('employee_id', $e->employee_id)->orderByDesc('start_date')->first();
            $data['contract'] = $contract === null ? null : [
                'type' => $contract->type,
                'pay_basis' => $contract->pay_basis,
                'pay' => Refs::money($contract->pay_minor, $contract->currency),
                'hours_per_week' => $contract->hours_per_week === null ? null : (string) $contract->hours_per_week,
                'start_date' => $contract->start_date->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
            ];
            app(PayFields::class)->recordView($e, 'employee');
        }

        return $data;
    }

    /**
     * Decision 42: contract pay is HR's `pay` group (HR-09): shown here only
     * to a reader holding `hr.pay.view` at the employee's place whose field
     * rules do not hide `hr_employee.pay`, as in HR itself.
     */
    private function seesContractPay(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && app(ScopeResolver::class)->can($user, 'hr.pay.view', $this->resource->scope())
            && ! in_array('pay', HidesFields::hidden($request, 'hr_employee'), true);
    }
}
