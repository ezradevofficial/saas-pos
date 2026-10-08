<?php

namespace App\Core\Workflow\Http\Controllers;

use App\Core\Workflow\Http\Requests\WorkflowVersionRequest;
use App\Core\Workflow\Http\Resources\WorkflowVersionResource;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\WorkflowVersion;

/** APR-09: one version of a flow, with its graph and the documents still running on it. */
class WorkflowVersionController
{
    public function show(WorkflowVersionRequest $request, WorkflowVersion $workflowVersion): WorkflowVersionResource
    {
        $workflowVersion->loadCount(['documents as in_progress_count' => fn ($q) => $q->where('status', DocumentWorkflow::RUNNING)]);

        return WorkflowVersionResource::make($workflowVersion)->withGraph();
    }
}
