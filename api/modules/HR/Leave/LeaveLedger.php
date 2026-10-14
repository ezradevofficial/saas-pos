<?php

namespace Modules\HR\Leave;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\HR\Models\LeaveEntry;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;

/**
 * HR-13: the leave ledger. Entries are appended once per key (an accrual
 * run, a request's days, an expiry), so repeated runs and redelivered
 * events post nothing twice. Balances are sums of entries effective on or
 * before a date; available = balance minus pending requests.
 */
class LeaveLedger
{
    /** Append an entry unless one with the same key exists; returns it, or null when it existed. */
    public function post(string $employeeId, string $leaveTypeId, string $kind, string $days, string $effectiveOn, string $key, ?string $requestId = null, ?string $note = null, ?string $by = null): ?LeaveEntry
    {
        $days = (string) BigDecimal::of($days)->toScale(2, RoundingMode::HalfUp);

        if (BigDecimal::of($days)->isZero()) {
            return null;
        }

        $inserted = LeaveEntry::query()->insertOrIgnore([
            'id' => (string) str()->uuid7(),
            'tenant_id' => app(TenantContext::class)->require(),
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'kind' => $kind,
            'days' => $days,
            'effective_on' => $effectiveOn,
            'key' => $key,
            'leave_request_id' => $requestId,
            'note' => $note,
            'created_by' => $by,
            'created_at' => now(),
        ]);

        return $inserted === 0 ? null : LeaveEntry::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('key', $key)->first();
    }

    public function balance(string $employeeId, string $leaveTypeId, ?CarbonImmutable $asOf = null): string
    {
        $sum = LeaveEntry::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->when($asOf !== null, fn ($q) => $q->where('effective_on', '<=', $asOf->toDateString()))
            ->sum('days');

        return (string) BigDecimal::of((string) $sum)->toScale(2, RoundingMode::HalfUp);
    }

    public function pending(string $employeeId, string $leaveTypeId, ?string $exceptRequestId = null): string
    {
        $sum = LeaveRequest::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('status', LeaveRequest::PENDING)
            ->when($exceptRequestId !== null, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->sum('days');

        return (string) BigDecimal::of((string) $sum)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * HR-15: an employee's position in a type: this year's credits
     * (accruals, carry-over, positive adjustments), taken this year (net of
     * reversals), the balance at the end of the year so far, pending and
     * available.
     *
     * @return array{entitled: string, taken: string, balance: string, pending: string, available: string}
     */
    public function summary(string $employeeId, LeaveType $type, CarbonImmutable $asOf): array
    {
        $yearStart = $asOf->startOfYear()->toDateString();
        $entries = LeaveEntry::query()->where('employee_id', $employeeId)->where('leave_type_id', $type->id)->get(['kind', 'days', 'effective_on']);
        $zero = BigDecimal::zero()->toScale(2);
        $entitled = $zero;
        $taken = $zero;
        $balance = $zero;

        foreach ($entries as $entry) {
            $days = BigDecimal::of((string) $entry->days);
            $date = $entry->effective_on->toDateString();

            if ($date <= $asOf->toDateString()) {
                $balance = $balance->plus($days);
            }

            if ($date < $yearStart || $date > $asOf->endOfYear()->toDateString()) {
                continue;
            }

            if (in_array($entry->kind, ['accrual', 'carry_over'], true) || ($entry->kind === 'adjustment' && $days->isPositive())) {
                $entitled = $entitled->plus($days);
            } elseif (in_array($entry->kind, ['taken', 'reversal'], true)) {
                $taken = $taken->minus($days);
            }
        }

        $pending = BigDecimal::of($this->pending($employeeId, $type->id));

        return [
            'entitled' => (string) $entitled->toScale(2),
            'taken' => (string) $taken->toScale(2),
            'balance' => (string) $balance->toScale(2),
            'pending' => (string) $pending->toScale(2),
            'available' => (string) $balance->minus($pending)->toScale(2),
        ];
    }
}
