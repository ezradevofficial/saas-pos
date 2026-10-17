<?php

namespace App\Core\Privacy\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NFR-08: one data subject request (docs/privacy.md).
 * received → approved → completed, or received → rejected. Audited by
 * PrivacyRequests at every step (AUD-01); never deleted (TEN-06).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $kind
 * @property string $status
 * @property ?string $note
 * @property ?array<string, mixed> $corrections
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $due_at
 * @property string $requested_by
 * @property ?string $approved_by
 * @property ?CarbonImmutable $approved_at
 * @property ?string $rejected_by
 * @property ?CarbonImmutable $rejected_at
 * @property ?string $rejection_reason
 * @property ?string $completed_by
 * @property ?CarbonImmutable $completed_at
 * @property ?array<string, mixed> $result
 * @property ?CarbonImmutable $archived_at
 */
class PrivacyRequest extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

    public const ACCESS = 'access';

    public const CORRECTION = 'correction';

    public const DELETION = 'deletion';

    public const KINDS = [self::ACCESS, self::CORRECTION, self::DELETION];

    public const RECEIVED = 'received';

    public const APPROVED = 'approved';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    protected $fillable = ['subject_type', 'subject_id', 'kind', 'note', 'corrections', 'received_at', 'due_at', 'requested_by'];

    protected function casts(): array
    {
        return [
            'corrections' => 'array',
            'result' => 'array',
            'received_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::RECEIVED, self::APPROVED], true);
    }

    public function isOverdue(?CarbonImmutable $at = null): bool
    {
        return $this->isOpen() && $this->due_at->isBefore($at ?? CarbonImmutable::now());
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
