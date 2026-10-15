<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PAY-14: a payroll run of a pay group and period (regular, or a
 * correction of a posted run, PAY-18). Posted runs are locked by the
 * database. Totals stay out of the audit log (PAY-23).
 *
 * @property string $number
 * @property string $company_id
 * @property string $pay_group_id
 * @property string $kind
 * @property ?string $corrects_run_id
 * @property ?string $correction_mode
 * @property ?array $correction_employee_ids
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $pay_date
 * @property string $status
 * @property ?array $totals
 * @property ?array $missing
 * @property ?string $created_by
 * @property ?string $calculated_by
 * @property ?string $submitted_by
 */
class Run extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const REGULAR = 'regular';

    public const CORRECTION = 'correction';

    public const DRAFT = 'draft';

    public const CALCULATED = 'calculated';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    public const REVERSE = 'reverse';

    public const RECALCULATE = 'recalculate';

    protected $table = 'payroll_runs';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'run';

    protected array $auditHidden = ['totals'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date', 'period_end' => 'immutable_date', 'pay_date' => 'immutable_date',
            'totals' => 'array', 'missing' => 'array', 'inputs' => 'array', 'correction_employee_ids' => 'array',
            'calculated_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payGroup(): BelongsTo
    {
        return $this->belongsTo(PayGroup::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RunLine::class);
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }
}
