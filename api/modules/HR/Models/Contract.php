<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-07: an employment contract. Pay is the reference Payroll starts from;
 * it is left out of the audit log (HR-11, recorded as `pay_changed`).
 *
 * @property string $employee_id
 * @property string $company_id
 * @property string $type
 * @property CarbonImmutable $start_date
 * @property ?CarbonImmutable $end_date
 * @property string $pay_basis
 * @property int $pay_minor
 * @property string $currency
 * @property string $status
 */
class Contract extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const TYPES = ['permanent', 'fixed_term', 'casual', 'internship', 'consultant'];

    public const PAY_BASES = ['monthly', 'daily', 'hourly'];

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    protected $table = 'hr_contracts';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'contract';

    protected array $auditHidden = ['pay_minor'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'probation_end_date' => 'immutable_date',
            'pay_minor' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
