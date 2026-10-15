<?php

namespace Modules\Payroll\Models;

use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PAY-09 to PAY-15: one employee's pay in a run, with the inputs, the
 * statutory results and the rates used (CUR-04). Amounts in minor units of
 * the pay currency; statutory amounts also in the pack currency. Recomputed
 * until the run is posted, then frozen (database trigger). Not audited
 * row by row: the run's calculation is.
 *
 * @property string $run_id
 * @property string $payroll_employee_id
 * @property string $employee_id
 * @property string $company_id
 * @property ?string $branch_id
 * @property ?string $location_id
 * @property ?string $department_id
 * @property string $number
 * @property string $name
 * @property string $pay_currency
 * @property ?string $statutory_currency
 * @property string $base_currency
 * @property ?array $fx
 * @property int $basic_minor
 * @property int $gross_minor
 * @property int $gross_statutory_minor
 * @property int $taxable_statutory_minor
 * @property int $statutory_minor
 * @property int $deductions_minor
 * @property int $loans_minor
 * @property int $net_minor
 * @property int $employer_minor
 * @property int $gross_base_minor
 * @property int $net_base_minor
 * @property int $employer_base_minor
 * @property array $earnings
 * @property array $deductions
 * @property array $statutory
 * @property array $loans
 * @property array $inputs
 * @property ?array $variance
 * @property array $errors
 * @property array $warnings
 */
class RunLine extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const MONEY = [
        'basic_minor', 'gross_minor', 'gross_statutory_minor', 'taxable_statutory_minor', 'statutory_minor', 'deductions_minor', 'loans_minor', 'net_minor',
        'employer_minor', 'gross_base_minor', 'net_base_minor', 'employer_base_minor',
    ];

    protected $table = 'payroll_run_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::MONEY, 'integer'),
            'fx' => 'array', 'earnings' => 'array', 'deductions' => 'array', 'statutory' => 'array', 'loans' => 'array',
            'inputs' => 'array', 'variance' => 'array', 'errors' => 'array', 'warnings' => 'array',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
