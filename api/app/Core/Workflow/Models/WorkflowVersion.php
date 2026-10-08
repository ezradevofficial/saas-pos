<?php

namespace App\Core\Workflow\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Workflow\Definitions\FlowGraph;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a flow (APR-09): a draft is edited; publishing makes it
 * immutable (a database trigger refuses changes to a published or archived
 * graph); the previously published version is archived. Documents keep
 * the version they started on.
 *
 * @property string $id
 * @property string $definition_id
 * @property int $version
 * @property string $status
 * @property array $graph
 */
class WorkflowVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'definition_id', 'version', 'status', 'graph', 'source', 'source_version_id',
        'created_by', 'updated_by', 'published_by', 'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'graph' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'definition_id');
    }

    /** Documents that ran or run on this version. */
    public function documents(): HasMany
    {
        return $this->hasMany(DocumentWorkflow::class, 'version_id');
    }

    public function flow(): FlowGraph
    {
        return FlowGraph::fromArray($this->graph ?? []);
    }
}
