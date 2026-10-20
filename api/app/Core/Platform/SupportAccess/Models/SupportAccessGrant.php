<?php

namespace App\Core\Platform\SupportAccess\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * RBAC-13: the tenant's consent to one support request. Staff ask with a
 * reason, a duration and a mode; the Owner or an Admin approves (choosing
 * whether sensitive data is included) or denies, and can revoke an
 * approval at any time. Access ends at `expires_at`. Audited in the
 * tenant's chain (`core.support_access.*`).
 *
 * @property string $id
 * @property string $platform_user_id
 * @property string $staff_name
 * @property string $staff_email
 * @property string $reason
 * @property string $mode
 * @property int $hours
 * @property bool $include_sensitive
 * @property string $status
 * @property Carbon $requested_at
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $expires_at
 * @property string|null $revoked_by
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property string|null $principal_user_id
 * @property Carbon|null $session_started_at
 */
class SupportAccessGrant extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const READ = 'read';

    public const WRITE = 'write';

    public const MODES = [self::READ, self::WRITE];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const CANCELLED = 'cancelled';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    public const ENDED = 'ended';

    /** RBAC-13a: the longest access a request may ask for. */
    public const MAX_HOURS = 72;

    protected string $auditResource = 'support_access';

    /** The entry code is a credential: never written to the audit log. */
    protected array $auditHidden = ['entry_code_hash', 'entry_code_expires_at', 'entry_code_agent_hash'];

    protected $hidden = ['entry_code_hash', 'entry_code_agent_hash'];

    protected $fillable = ['platform_user_id', 'staff_name', 'staff_email', 'reason', 'mode', 'hours', 'include_sensitive', 'status', 'requested_at',
        'decided_by', 'decided_at', 'decision_note', 'expires_at', 'revoked_by', 'ended_at', 'end_reason', 'principal_user_id', 'session_started_at'];

    protected $attributes = ['status' => self::PENDING, 'include_sensitive' => false];

    protected function casts(): array
    {
        return [
            'hours' => 'integer',
            'include_sensitive' => 'boolean',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'entry_code_expires_at' => 'datetime',
            'session_started_at' => 'datetime',
        ];
    }

    /** Approved, and not yet at its end. */
    public function isActive(?CarbonInterface $at = null): bool
    {
        return $this->status === self::APPROVED && $this->expires_at !== null && $this->expires_at->isAfter($at ?? now());
    }

    /** The status as the tenant and staff see it: an approval past its end reads `expired` before the sweep marks it. */
    public function effectiveStatus(): string
    {
        return $this->status === self::APPROVED && ! $this->isActive() ? self::EXPIRED : $this->status;
    }
}
