<?php

namespace App\Core\Workflow\Http\Resources;

use App\Core\Workflow\Models\WorkflowVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * APR-09: one version of a flow. `in_progress` counts the documents still
 * running on it ("6 requisitions in progress stay on v3"); the graph is
 * included when the resource is built with it (show, draft saves).
 *
 * @mixin WorkflowVersion
 */
class WorkflowVersionResource extends JsonResource
{
    private bool $withGraph = false;

    public function withGraph(bool $with = true): static
    {
        $this->withGraph = $with;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->definition_id,
            'version' => $this->version,
            'status' => $this->status,
            'source' => $this->source,
            'source_version_id' => $this->source_version_id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'published_by' => $this->published_by,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            // WF-02: a discarded draft is archived but was never live (no roll back).
            'discarded_at' => $this->discarded_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'in_progress' => $this->when(isset($this->in_progress_count), fn () => (int) $this->in_progress_count),
            'graph' => $this->when($this->withGraph, fn () => $this->graph),
        ];
    }
}
