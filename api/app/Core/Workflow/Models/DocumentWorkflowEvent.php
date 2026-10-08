<?php

namespace App\Core\Workflow\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a document's flow history (WF-10), append-only (a trigger
 * refuses changes). Types: started, entered, left, skipped, condition,
 * split, joined, action, returned, cancelled, completed.
 *
 * @property string $type
 * @property ?string $node_id
 * @property ?string $user_id
 * @property ?string $reason
 * @property array $data
 */
class DocumentWorkflowEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPES = ['started', 'entered', 'left', 'skipped', 'condition', 'split', 'joined', 'action', 'returned', 'cancelled', 'completed'];

    public $timestamps = false;

    protected $fillable = ['workflow_id', 'type', 'node_id', 'user_id', 'reason', 'data', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflow::class, 'workflow_id');
    }
}
