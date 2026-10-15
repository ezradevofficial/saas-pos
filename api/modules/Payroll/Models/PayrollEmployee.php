<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PAY-05: Payroll's copy of an HR employee (fed by HR's events and its
 * `Employees` contract, never by reading HR's tables) with the pay setup.
 * Its place (location, else branch, else company) is where its pay data's
 * permissions are checked (PAY-27).
 *
 * @property string $employee_id
 * @property string $company_id
 * @property string $number
 * @property string $name
 * @property ?string $branch_id
 * @property ?string $location_id
 * @property ?string $department_id
 * @property ?string $user_id
 * @property string $status
 * @property ?string $pay_group_id
 * @property ?string $pay_currency
 * @property ?string $payment_method
 * @property int $dependants
 * @property int $children
 * @property bool $on_hold
 * @property CarbonImmutable $hire_date
 * @property ?CarbonImmutable $termination_date
 */
class PayrollEmployee extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const TERMINATED = 'terminated';

    public const METHODS = ['bank', 'mobile_money', 'cash'];

    protected $table = 'payroll_employees';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'employee';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hire_date' => 'immutable_date',
            'termination_date' => 'immutable_date',
            'synced_at' => 'immutable_datetime',
            'on_hold' => 'boolean',
            'dependants' => 'integer',
            'children' => 'integer',
        ];
    }

    public function scope(): Scope
    {
        return match (true) {
            $this->location_id !== null => Scope::location($this->location_id),
            $this->branch_id !== null => Scope::branch($this->branch_id),
            default => Scope::company($this->company_id),
        };
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function payGroup(): BelongsTo
    {
        return $this->belongsTo(PayGroup::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeItem::class);
    }

    /** PAY-05: the currency they are paid in: their own setting, else their latest contract's, else the company's base. */
    public function effectivePayCurrency(): string
    {
        return $this->pay_currency
            ?? PayrollContract::query()->where('employee_id', $this->employee_id)->orderByDesc('start_date')->value('currency')
            ?? Company::query()->whereKey($this->company_id)->value('base_currency');
    }

    /** Employed on any day of [$from, $to]. */
    public function employedDuring(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->hire_date->lte($to) && ($this->termination_date === null || $this->termination_date->gte($from));
    }
}
