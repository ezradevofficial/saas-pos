<?php

namespace Modules\Payroll\Contracts;

use Illuminate\Support\Str;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\Item;
use Modules\Payroll\Models\PayrollEmployee;

/**
 * A write contract for other modules that earn money for an employee (Salon's
 * approved commissions, SAL-24): one one-off earning of a company's earning
 * item for a pay period date. Payroll's setup decides everything else: the
 * item's tax treatment is the company's (nothing is assumed here), the
 * employee must be in payroll and paid in the earning's currency, and the
 * earning is read by the next regular run whose period holds the date.
 *
 * Idempotent: the same `$sourceKey` for the same employee adds nothing the
 * second time. Callers use it only while Payroll is active for the tenant and
 * only for amounts already approved on their side.
 */
final class ExternalEarnings
{
    public const ADDED = 'added';

    public const EXISTS = 'exists';

    public const REFUSED = 'refused';

    /**
     * @return array{status: string, id?: string, reason?: string} `reason`: employee_unknown, employee_inactive, item_unknown, currency_differs, period_date_invalid, amount_invalid
     */
    public function addOnce(string $companyId, string $hrEmployeeId, string $itemCode, int $amountMinor, string $currency, string $periodDate, string $sourceKey, ?string $createdBy, ?string $note = null): array
    {
        if ($amountMinor < 1) {
            return ['status' => self::REFUSED, 'reason' => 'amount_invalid'];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodDate) !== 1) {
            return ['status' => self::REFUSED, 'reason' => 'period_date_invalid'];
        }

        $employee = Str::isUuid($hrEmployeeId) ? PayrollEmployee::query()->where('company_id', $companyId)->where('employee_id', $hrEmployeeId)->first() : null;

        if ($employee === null) {
            return ['status' => self::REFUSED, 'reason' => 'employee_unknown'];
        }

        if ($employee->status !== PayrollEmployee::ACTIVE) {
            return ['status' => self::REFUSED, 'reason' => 'employee_inactive'];
        }

        $item = Item::query()->where('company_id', $companyId)->where('kind', Item::EARNING)->whereNull('archived_at')->whereRaw('lower(code) = lower(?)', [$itemCode])->first();

        if ($item === null) {
            return ['status' => self::REFUSED, 'reason' => 'item_unknown'];
        }

        if ($employee->pay_currency !== null && $employee->pay_currency !== $currency) {
            return ['status' => self::REFUSED, 'reason' => 'currency_differs'];
        }

        $key = mb_substr('ext:'.$sourceKey, 0, 250);
        $existing = EmployeeItem::query()->where('payroll_employee_id', $employee->id)->where('item_id', $item->id)->where('note', $key)->first();

        if ($existing !== null) {
            return ['status' => self::EXISTS, 'id' => (string) $existing->id];
        }

        $entry = EmployeeItem::query()->create([
            'payroll_employee_id' => $employee->id,
            'item_id' => $item->id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'recurrence' => EmployeeItem::ONCE,
            'period_date' => $periodDate,
            'note' => $key,
            'created_by' => $createdBy,
        ]);

        return ['status' => self::ADDED, 'id' => (string) $entry->id];
    }
}
