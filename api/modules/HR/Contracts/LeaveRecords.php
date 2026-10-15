<?php

namespace Modules\HR\Contracts;

use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;

/**
 * HR-33 (stage 7C review): a company's unpaid leave as it stands, the same
 * values LeaveApproved and LeaveCancelled carry, so Payroll switched on
 * after HR (or after a lost event) gets the history. Leave is not one of
 * the sensitive groups (HR-09); no reason or document is returned.
 */
final class LeaveRecords
{
    /**
     * @return list<array{request_id: string, employee_id: string, company_id: string, status: string, dates: list<array{date: string, fraction: string}>}>
     *                                                                                                                                                      unpaid requests that were approved (`approved`) or cancelled after approval (`cancelled`)
     */
    public function unpaidOfCompany(string $companyId): array
    {
        $unpaid = LeaveType::query()->where('company_id', $companyId)->where('paid', false)->pluck('id')->all();

        if ($unpaid === []) {
            return [];
        }

        return LeaveRequest::query()->where('company_id', $companyId)->whereIn('leave_type_id', $unpaid)
            ->where(fn ($q) => $q->where('status', LeaveRequest::APPROVED)->orWhere(fn ($c) => $c->where('status', LeaveRequest::CANCELLED)->whereNotNull('decided_at')))
            ->orderBy('start_date')->get()
            ->map(fn (LeaveRequest $r) => [
                'request_id' => $r->id,
                'employee_id' => $r->employee_id,
                'company_id' => $r->company_id,
                'status' => $r->status === LeaveRequest::APPROVED ? 'approved' : 'cancelled',
                'dates' => array_values((array) $r->dates),
            ])->all();
    }
}
