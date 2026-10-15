<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** HR-26: an attendance period of a company (closed once, never reopened by HR). */
class AttendancePeriod extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'hr_attendance_periods';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'attendance_period';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'totals' => 'array'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
