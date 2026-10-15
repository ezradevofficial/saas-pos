<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR-19: a shift swap or give-away, accepted by the peer, then decided through `hr.shift_swap`. */
class ShiftSwap extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const AWAITING_PEER = 'awaiting_peer';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::AWAITING_PEER, self::PENDING, self::APPROVED, self::REJECTED, self::DECLINED, self::CANCELLED];

    protected $table = 'hr_shift_swaps';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'shift_swap';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['answered_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Employee::placeScope($this->company_id, $this->branch_id, $this->location_id);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'peer_employee_id');
    }

    public function rosterShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class);
    }

    public function peerRosterShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class, 'peer_roster_shift_id');
    }
}
