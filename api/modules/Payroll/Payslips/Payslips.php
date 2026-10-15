<?php

namespace Modules\Payroll\Payslips;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\HR\Contracts\Employees;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Support\Access;
use Modules\Payroll\Support\PayFields;

/**
 * PAY-19, PAY-24: who sees a payslip. The employee themself (the user HR
 * links to them, HR-31; no permission needed) and holders of
 * `payroll.payslip.view` at the employee's place whose field rules do not
 * hide pay amounts (RBAC-05). Nobody else, whatever else they hold: a
 * payslip out of reach is not found. Every view and download is audited.
 */
class Payslips
{
    public const VIEW = 'payroll.payslip.view';

    public function __construct(
        private readonly Employees $employees,
        private readonly Access $access,
        private readonly PayFields $fields,
        private readonly Auditor $auditor,
    ) {}

    /** @return list<string> HR employee ids linked to $user */
    public function ownEmployeeIds(User $user): array
    {
        return $this->employees->linkedTo($user->id);
    }

    public function isOwn(User $user, Payslip $payslip): bool
    {
        return in_array($payslip->employee_id, $this->ownEmployeeIds($user), true);
    }

    /**
     * As a payroll reader: the permission at the place the employee had on
     * the payslip (its run line's branch and location, review fix 10, not
     * where they work now) and amounts not hidden.
     */
    public function readsAsPayroll(User $user, Payslip $payslip): bool
    {
        $line = RunLine::query()->find($payslip->run_line_id);

        return $line !== null && $this->access->can($user, self::VIEW, $line) && ! $this->fields->hides(PayFields::AMOUNTS);
    }

    public function sees(User $user, Payslip $payslip): bool
    {
        return $this->isOwn($user, $payslip) || $this->readsAsPayroll($user, $payslip);
    }

    /** Payslips a payroll reader may list. */
    public function limit(Builder $query, User $user): Builder
    {
        return $this->access->limitPlaces($query, $user, self::VIEW, 'run_line_id', 'payroll_run_lines');
    }

    public function recordView(Payslip $payslip, string $context): void
    {
        $this->auditor->record('payroll.payslip.'.$context, $payslip, null, ['number' => $payslip->number, 'employee_id' => $payslip->employee_id]);
    }
}
