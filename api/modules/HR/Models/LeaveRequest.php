<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-14: a leave request, decided through the `hr.leave_request` flow.
 * Its place is the employee's at the time of the request.
 *
 * @property string $employee_id
 * @property string $leave_type_id
 * @property string $status
 * @property string $days
 * @property list<array{date: string, fraction: string}> $dates
 */
class LeaveRequest extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    protected $table = 'hr_leave_requests';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'leave_request';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'start_half' => 'boolean',
            'end_half' => 'boolean',
            'dates' => 'array',
            'research' => 'array',
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function scope(): Scope
    {
        return Employee::placeScope($this->company_id, $this->branch_id, $this->location_id);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
