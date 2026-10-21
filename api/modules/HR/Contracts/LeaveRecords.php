<?php

namespace Modules\HR\Contracts;

use DateTimeInterface;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;

/**
 * HR-33 (stage 7C review): a company's unpaid leave (and, CP-01, leave
 * whose pay is not confirmed or that has half-pay days) as it stands, the same
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
    public function unpaidOfCompany(string $companyId, ?DateTimeInterface $since = null): array
    {
        // CP-01, HR-12: unpaid types, types whose pay is not confirmed (paid null), and requests with half-pay days.
        $types = LeaveType::query()->where('company_id', $companyId)->pluck('paid', 'id')->all();
        $watched = array_keys(array_filter($types, fn ($paid) => $paid !== true));

        return LeaveRequest::query()->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereIn('leave_type_id', $watched)->orWhere('half_pay_days', '>', 0))
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>=', $since))
            ->where(fn ($q) => $q->where('status', LeaveRequest::APPROVED)->orWhere(fn ($c) => $c->where('status', LeaveRequest::CANCELLED)->whereNotNull('decided_at')))
            ->orderBy('start_date')->get()
            ->map(fn (LeaveRequest $r) => [
                'request_id' => $r->id,
                'employee_id' => $r->employee_id,
                'company_id' => $r->company_id,
                'status' => $r->status === LeaveRequest::APPROVED ? 'approved' : 'cancelled',
                'dates' => array_values((array) $r->dates),
                'paid' => array_key_exists($r->leave_type_id, $types) ? $types[$r->leave_type_id] : true,
                'half_pay_days' => (string) ($r->half_pay_days ?? '0'),
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])->all();
    }
}
