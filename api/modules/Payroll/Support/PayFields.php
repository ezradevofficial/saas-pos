<?php

namespace Modules\Payroll\Support;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Http\Resources\HidesFields;
use App\Core\Rbac\FieldRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * PAY-23, RBAC-05: field rules on resource `payroll`. `amounts` hides the
 * per-employee amounts (pay setup, items, loans, run lines, payslips) and
 * `totals` the run totals: hidden fields are left out of responses, lists
 * and exports, and a read-only `amounts` refuses changes to pay
 * (`field_readonly`). Showing pay in full is audited once per subject and
 * request (`payroll.pay.view`).
 */
class PayFields
{
    public const RESOURCE = 'payroll';

    public const AMOUNTS = 'amounts';

    public const TOTALS = 'totals';

    public const FIELDS = [self::AMOUNTS, self::TOTALS];

    /** @var array<string, true> */
    private array $audited = [];

    public function __construct(private readonly Auditor $auditor) {}

    /** Whether $field is hidden from the request's user. */
    public function hides(string $field, ?Request $request = null): bool
    {
        return in_array($field, HidesFields::hidden($request ?? request(), self::RESOURCE), true);
    }

    /** Whether $user may change pay amounts (not hidden or read-only for them). */
    public function mayEditAmounts(User $user): bool
    {
        $rules = app(FieldRules::class)->for($user, self::RESOURCE);

        return ! in_array(self::AMOUNTS, [...$rules['hidden'], ...$rules['readonly']], true);
    }

    /** 422 `field_readonly` unless $user may change pay amounts. */
    public function assertEditable(User $user): void
    {
        if (! $this->mayEditAmounts($user)) {
            throw Errors::make(422, 'field_readonly', ['field' => self::AMOUNTS], 'amount_minor');
        }
    }

    /** PAY-23: record that $what of $subject was shown (once per subject and context in this request). */
    public function recordView(Model $subject, string $context, array $extra = []): void
    {
        $key = $subject->getKey().'|'.$context;

        if (isset($this->audited[$key])) {
            return;
        }

        $this->audited[$key] = true;
        $this->auditor->record('payroll.pay.view', $subject, null, ['context' => $context, ...$extra]);
    }
}
