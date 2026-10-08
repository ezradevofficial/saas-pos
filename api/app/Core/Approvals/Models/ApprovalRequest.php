<?php

namespace App\Core\Approvals\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Workflow\Models\DocumentWorkflow;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of a document into an approval node (APR-01): the node's
 * configuration from the flow version the document runs on (APR-09),
 * where the document belongs, a summary for the inbox (APR-04), and the
 * reminder and escalation timers (APR-05).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_id
 * @property string $token_id
 * @property string $version_id
 * @property string $node_id
 * @property ?string $node_name
 * @property string $document_type
 * @property string $document_id
 * @property ?string $company_id
 * @property ?string $branch_id
 * @property ?string $location_id
 * @property ?string $requester_id
 * @property array<string, mixed> $config
 * @property string $mode
 * @property int $step
 * @property int $steps
 * @property string $status
 * @property ?string $blocked_reason
 */
class ApprovalRequest extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /** Left without a decision because a parallel branch finished first. */
    public const EXPIRED = 'expired';

    /** APR-07: nobody eligible to approve; an admin reassigns (APR-06). */
    public const BLOCKED_NO_APPROVER = 'no_approver';

    protected $fillable = [
        'workflow_id', 'token_id', 'version_id', 'node_id', 'node_name', 'document_type', 'document_id',
        'company_id', 'branch_id', 'location_id', 'document_number', 'document_title', 'amount_minor', 'currency',
        'requester_id', 'config', 'mode', 'step', 'steps', 'status', 'blocked_reason', 'received_at', 'due_at',
        'level_started_at', 'escalation_level', 'escalated_depth', 'escalate_at', 'reminders_sent', 'next_reminder_at',
        'outcome', 'auto_decided', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'step' => 'integer',
            'steps' => 'integer',
            'escalation_level' => 'integer',
            'escalated_depth' => 'integer',
            'reminders_sent' => 'integer',
            'auto_decided' => 'boolean',
            'received_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'level_started_at' => 'immutable_datetime',
            'escalate_at' => 'immutable_datetime',
            'next_reminder_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflow::class, 'workflow_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ApprovalAssignment::class, 'request_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ApprovalAttachment::class, 'request_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** @return array{amount_minor: string, currency: string}|null */
    public function amount(): ?array
    {
        return $this->amount_minor === null ? null : ['amount_minor' => (string) $this->amount_minor, 'currency' => (string) $this->currency];
    }
}
