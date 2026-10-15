<?php

namespace Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PAY-19: the payslip of one employee in a posted run.
 *
 * @property string $run_id
 * @property string $run_line_id
 * @property string $payroll_employee_id
 * @property string $employee_id
 * @property string $company_id
 * @property string $number
 * @property CarbonImmutable $issued_at
 */
class Payslip extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'payroll_payslips';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(RunLine::class, 'run_line_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }
}
