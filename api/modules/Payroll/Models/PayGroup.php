<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PAY-03: a pay group of a company (frequency, anchor and pay day).
 * Archived, never deleted.
 *
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $frequency
 * @property ?CarbonImmutable $anchor_date
 * @property ?int $pay_day
 */
class PayGroup extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const MONTHLY = 'monthly';

    public const WEEKLY = 'weekly';

    public const BIWEEKLY = 'biweekly';

    public const FREQUENCIES = [self::MONTHLY, self::WEEKLY, self::BIWEEKLY];

    protected $table = 'payroll_pay_groups';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'pay_group';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['anchor_date' => 'immutable_date', 'pay_day' => 'integer'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Pay periods a year (12, 52 or 26). */
    public function periodsPerYear(): int
    {
        return match ($this->frequency) {
            self::WEEKLY => 52,
            self::BIWEEKLY => 26,
            default => 12,
        };
    }

    /**
     * The period containing $date: its calendar month, or the 7 or 14 days
     * counted from the anchor date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodOf(CarbonImmutable $date): array
    {
        if ($this->frequency === self::MONTHLY) {
            return [$date->startOfMonth()->startOfDay(), $date->endOfMonth()->startOfDay()];
        }

        $length = $this->frequency === self::WEEKLY ? 7 : 14;
        $offset = (int) floor($this->anchor_date->diffInDays($date->startOfDay(), false) / $length);
        $start = $this->anchor_date->addDays($offset * $length);

        return [$start, $start->addDays($length - 1)];
    }

    /** The pay date of the period ending $end: its pay day in the month (capped to the month's end), else $end. */
    public function payDateFor(CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        if ($this->frequency !== self::MONTHLY || $this->pay_day === null) {
            return $end;
        }

        return $start->day(min($this->pay_day, $start->daysInMonth));
    }
}
