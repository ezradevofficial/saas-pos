<?php

namespace Modules\HR\Support;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Http\Resources\HidesFields;
use App\Core\Rbac\FieldRules;
use Illuminate\Http\Request;
use Modules\HR\Models\Employee;

/**
 * HR-09, HR-11: the sensitive groups of an employee's data. A group is
 * shown to a reader who holds its view permission at the employee's place
 * and whose field rules (RBAC-05, resource `hr_employee`) do not hide its
 * field; the rest is masked or left out. Showing a group in full is
 * audited once per employee and request (`hr.employee.sensitive_view`).
 */
class Sensitive
{
    public const RESOURCE = 'hr_employee';

    public const IDENTITY = 'identity';

    public const BANK = 'bank';

    public const PAY = 'pay';

    public const MEDICAL = 'medical';

    public const DISCIPLINARY = 'disciplinary';

    /** group => [view permission, edit permission, the field rules fields it covers]. */
    public const GROUPS = [
        self::IDENTITY => ['hr.identity.view', 'hr.identity.edit', ['identifiers', 'date_of_birth']],
        self::BANK => ['hr.bank.view', 'hr.bank.edit', ['pay_accounts']],
        self::PAY => ['hr.pay.view', 'hr.pay.edit', ['pay']],
        self::MEDICAL => ['hr.medical.view', 'hr.medical.edit', ['medical_notes']],
        self::DISCIPLINARY => ['hr.disciplinary.view', 'hr.disciplinary.manage', ['disciplinary']],
    ];

    /** Fields of personal (not sensitive-group) data that field rules may hide too. */
    public const PERSONAL_FIELDS = ['personal_email', 'personal_phone', 'address', 'emergency_contacts'];

    /** Every field `hr_employee` field rules may name. */
    public const FIELDS = ['identifiers', 'date_of_birth', 'pay_accounts', 'pay', 'medical_notes', 'disciplinary', ...self::PERSONAL_FIELDS];

    /** HR-27: the group a document category belongs to (null: the general `hr.document.view`). */
    public const DOCUMENT_GROUPS = ['contract' => self::PAY, 'identity' => self::IDENTITY, 'medical' => self::MEDICAL, 'disciplinary' => self::DISCIPLINARY];

    /** @var array<string, true> employee|groups already audited in this request */
    private array $audited = [];

    public function __construct(
        private readonly Access $access,
        private readonly Auditor $auditor,
    ) {}

    /** Whether $user sees $group of $employee in full (permission at the place, not hidden by field rules). */
    public function sees(User $user, Employee $employee, string $group, ?Request $request = null): bool
    {
        [$view, , $fields] = self::GROUPS[$group];

        if (! $this->access->can($user, $view, $employee)) {
            return false;
        }

        return array_intersect($fields, $this->hiddenFields($user, $request)) === [];
    }

    /** Whether field $field (not of a group) is hidden from $user by field rules. */
    public function hides(User $user, string $field, ?Request $request = null): bool
    {
        return in_array($field, $this->hiddenFields($user, $request), true);
    }

    /** Whether $user may change $group of $employee (edit permission at the place, field not hidden or read-only). */
    public function mayEdit(User $user, Employee $employee, string $group): bool
    {
        [, $edit, $fields] = self::GROUPS[$group];

        if (! $this->access->can($user, $edit, $employee)) {
            return false;
        }

        $rules = app(FieldRules::class)->for($user, self::RESOURCE);

        return array_intersect($fields, [...$rules['hidden'], ...$rules['readonly']]) === [];
    }

    /** @return list<string> the `hr_employee` fields hidden from $user (cached on the request) */
    public function hiddenFields(User $user, ?Request $request = null): array
    {
        $request ??= request();

        if ($request->user()?->id === $user->id) {
            return HidesFields::hidden($request, self::RESOURCE);
        }

        return app(FieldRules::class)->for($user, self::RESOURCE)['hidden'];
    }

    /**
     * HR-11: record that $groups of $employee were shown in full to the
     * current user (once per employee and group set in this request).
     *
     * @param  list<string>  $groups
     */
    public function recordView(Employee $employee, array $groups, string $context = 'record'): void
    {
        if ($groups === []) {
            return;
        }

        sort($groups);
        $key = $employee->id.'|'.implode(',', $groups);

        if (isset($this->audited[$key])) {
            return;
        }

        $this->audited[$key] = true;
        $this->auditor->record('hr.employee.sensitive_view', $employee, null, ['groups' => $groups, 'context' => $context]);
    }

    /** The masked form of a value: its last 4 characters after bullets. */
    public static function mask(?string $hint): ?string
    {
        return $hint === null ? null : '••••'.$hint;
    }

    public static function hintOf(string $value): string
    {
        $clean = (string) preg_replace('/\s+/u', '', $value);

        return mb_substr($clean, -4);
    }
}
