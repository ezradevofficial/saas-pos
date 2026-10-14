<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-17, HR-18: one planned shift of an employee on a date (one planned
 * per employee and date). Its place is the employee's.
 *
 * @property string $employee_id
 * @property CarbonImmutable $date
 * @property CarbonImmutable $start_at
 * @property CarbonImmutable $end_at
 * @property string $status
 */
class RosterShift extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const PLANNED = 'planned';

    public const CANCELLED = 'cancelled';

    protected $table = 'hr_roster_shifts';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'roster_shift';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'start_at' => 'immutable_datetime', 'end_at' => 'immutable_datetime', 'break_minutes' => 'integer'];
    }

    public function scope(): Scope
    {
        return Employee::placeScope($this->company_id, $this->branch_id, $this->location_id);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
