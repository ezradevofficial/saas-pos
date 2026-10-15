<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PAY-06: an employee's recurring or one-off earning or deduction, in the
 * pay currency. Amounts stay out of the audit log (PAY-23: audit readers
 * do not read pay).
 *
 * @property string $payroll_employee_id
 * @property string $item_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $recurrence
 * @property ?CarbonImmutable $from_date
 * @property ?CarbonImmutable $to_date
 * @property ?CarbonImmutable $period_date
 * @property ?string $consumed_run_id
 */
class EmployeeItem extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const RECURRING = 'recurring';

    public const ONCE = 'once';

    protected $table = 'payroll_employee_items';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'employee_item';

    protected array $auditHidden = ['amount_minor'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'immutable_date', 'to_date' => 'immutable_date', 'period_date' => 'immutable_date', 'amount_minor' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }
}
