<?php

namespace App\Core\Workflow\Models;

use App\Core\Tenancy\BelongsToTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a document is in its flow (WF-06, WF-10): one row per position.
 * Active rows wait at a stage or approval; waiting rows sit at a join until
 * their parallel branches arrive; done and cancelled rows are history.
 * `groups` lists the parallel branches ("{split group}#{branch}") the position is inside, innermost
 * last. `due_at` comes from the stage's time limit (WF-09).
 *
 * @property string $id
 * @property string $workflow_id
 * @property string $node_id
 * @property string $status
 * @property list<string> $groups
 */
class DocumentWorkflowToken extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['workflow_id', 'node_id', 'status', 'groups', 'entered_at', 'due_at', 'left_at', 'entered_by', 'left_by'];

    protected function casts(): array
    {
        return [
            'groups' => 'array',
            'entered_at' => 'datetime',
            'due_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflow::class, 'workflow_id');
    }

    /**
     * WF-09: active positions past their due time (for reminders and
     * escalation, APR-05), oldest first.
     */
    public function scopeOverdue(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $at ?? now())
            ->orderBy('due_at');
    }
}
