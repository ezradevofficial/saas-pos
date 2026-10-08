<?php

namespace App\Core\Workflow\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document's run of a flow version (WF-04..WF-11). It stays on its
 * version until it completes or is cancelled (APR-09).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_type
 * @property string $document_id
 * @property ?string $company_id
 * @property string $version_id
 * @property string $status
 * @property ?string $outcome
 */
class DocumentWorkflow extends Model
{
    use BelongsToTenant, HasUuids;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'document_type', 'document_id', 'company_id', 'version_id', 'status', 'outcome',
        'started_by', 'started_at', 'completed_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(DocumentWorkflowToken::class, 'workflow_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentWorkflowEvent::class, 'workflow_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(DocumentWorkflowLink::class, 'workflow_id');
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }
}
