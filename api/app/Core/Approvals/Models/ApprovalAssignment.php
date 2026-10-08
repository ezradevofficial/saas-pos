<?php

namespace App\Core\Approvals\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who must act on a request (APR-01, APR-02), at one step of a
 * sequential chain. A delegate acts on the row of the user who delegated
 * (APR-06): `decided_by` is the delegate, `on_behalf_of` that user.
 *
 * @property string $id
 * @property string $request_id
 * @property string $user_id
 * @property int $step
 * @property string $source
 * @property string $status
 * @property ?string $decided_by
 * @property ?string $on_behalf_of
 * @property ?string $reassigned_from
 * @property ?string $reassigned_by
 */
class ApprovalAssignment extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RETURNED = 'returned';

    /** The request was decided or left without this person's decision. */
    public const CLOSED = 'closed';

    /** An admin moved it to someone else (APR-06). */
    public const REASSIGNED = 'reassigned';

    public const SOURCE_RESOLVED = 'resolved';

    /** APR-07: nobody eligible resolved; the next eligible approver took the step. */
    public const SOURCE_FALLBACK = 'fallback';

    /** APR-05: added by escalation; their decision alone decides the step. */
    public const SOURCE_ESCALATED = 'escalated';

    public const SOURCE_REASSIGNED = 'reassigned';

    protected $fillable = [
        'request_id', 'user_id', 'step', 'source', 'reassigned_from', 'reassigned_by', 'status',
        'decided_by', 'on_behalf_of', 'decided_at', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
