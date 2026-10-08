<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowVersion;

/** APR-09: GET workflow-versions/{workflow_version}: one version with its graph, for anyone who sees its flow. */
class WorkflowVersionRequest extends WorkflowRequest
{
    public function definition(): WorkflowDefinition
    {
        return $this->version()->definition()->firstOrFail();
    }

    public function version(): WorkflowVersion
    {
        return $this->route('workflow_version');
    }
}
