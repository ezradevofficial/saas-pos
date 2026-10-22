<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-12: a leave type of a company. Statutory types come from the country
 * pack with `needs_confirmation` until a person enters their days.
 *
 * @property string $company_id
 * @property string $code
 * @property string $accrual
 * @property ?string $entitlement_days
 * @property bool $needs_confirmation
 * @property string $count_basis
 * @property ?string $carry_over_max_days
 * @property bool $allow_negative
 * @property ?bool $paid null: the pay rate is not confirmed (CP-01)
 * @property ?array{every_years: int, days: int} $seniority more yearly days per completed years of service (HR-13)
 * @property ?array{age: int, days: int, confidence?: string, researched_on?: string, source?: list<string>} $under_age other yearly days for workers under an age (HR-13)
 * @property ?string $pay_share a paid type's share of pay as an exact fraction ("2/3"); null is full pay (CP-01)
 * @property ?int $qualifying_months months of service before it can be taken
 * @property ?string $half_pay_days days at half pay after the full-pay days, in a year
 * @property ?list<array{key: string, days: int}> $events per-event days within the yearly days
 * @property ?array{confidence: string, researched_on: string, source: list<string>, assumed: list<string>} $research CP-01
 */
class LeaveType extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const ACCRUALS = ['none', 'annual', 'monthly'];

    public const COUNT_BASES = ['working_days', 'calendar_days'];

    protected $table = 'hr_leave_types';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'leave_type';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid' => 'boolean',
            'needs_confirmation' => 'boolean',
            'allow_negative' => 'boolean',
            'requires_document' => 'boolean',
            'accrues_from' => 'immutable_date',
            'events' => 'array',
            'research' => 'array',
            'seniority' => 'array',
            'under_age' => 'array',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    /** CP-01: its days come from public research (the country pack), not yet confirmed. */
    public function researched(): bool
    {
        return $this->research !== null;
    }

    /** HR-13: the days credited a year: the full-pay days and any half-pay days after them. */
    public function yearlyDays(): ?string
    {
        if ($this->entitlement_days === null) {
            return null;
        }

        return (string) BigDecimal::of((string) $this->entitlement_days)->plus((string) ($this->half_pay_days ?? '0'));
    }

    /** HR-12: usable for requests and accrual only once its days are confirmed. */
    public function usable(): bool
    {
        return ! $this->needs_confirmation;
    }
}
