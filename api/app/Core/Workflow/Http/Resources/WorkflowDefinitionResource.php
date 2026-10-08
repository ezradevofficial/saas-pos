<?php

namespace App\Core\Workflow\Http\Resources;

use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WF-02, spec 6.4: a flow with its live and draft versions ("Draft v4 ·
 * v3 is live"). Graphs are included when built withGraphs() (show).
 *
 * @mixin WorkflowDefinition
 */
class WorkflowDefinitionResource extends JsonResource
{
    private bool $withGraphs = false;

    public function withGraphs(bool $with = true): static
    {
        $this->withGraphs = $with;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $type = app(DocumentTypeRegistry::class)->find($this->document_type);
        $version = fn (?WorkflowVersion $v) => $v === null ? null : WorkflowVersionResource::make($v)->withGraph($this->withGraphs)->resolve($request);

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_type_label' => $type === null ? $this->document_type : __($type->label()),
            'company_id' => $this->company_id,
            'company_name' => $this->company_id === null ? null : $this->company?->name,
            'published' => $version($this->published),
            'draft' => $version($this->draft),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
