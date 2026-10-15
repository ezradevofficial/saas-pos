<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PAY-13: a loan or salary advance to an employee, interest-free, repaid
 * by instalments deducted from pay. Its balance is its ledger's sum.
 * Amounts stay out of the audit log (PAY-23).
 *
 * @property string $payroll_employee_id
 * @property string $company_id
 * @property string $kind
 * @property int $principal_minor
 * @property int $instalment_minor
 * @property string $currency
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $disbursed_on
 * @property bool $settle_next_run
 * @property string $status
 */
class Loan extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const KINDS = ['loan', 'advance'];

    public const ACTIVE = 'active';

    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    protected $table = 'payroll_loans';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'loan';

    protected array $auditHidden = ['principal_minor', 'instalment_minor'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'disbursed_on' => 'immutable_date', 'starts_on' => 'immutable_date', 'settle_next_run' => 'boolean',
            'principal_minor' => 'integer', 'instalment_minor' => 'integer',
        ];
    }

    /** The employee's place (PAY-27). */
    public function scope(): Scope
    {
        return $this->employee->scope();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LoanEntry::class)->orderBy('created_at')->orderBy('id');
    }

    /** The outstanding balance (the ledger's sum). */
    public function balance(): int
    {
        return (int) LoanEntry::query()->where('loan_id', $this->id)->sum('amount_minor');
    }
}
