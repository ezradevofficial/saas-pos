<?php

namespace App\Core\Workflow\Http\Requests;

/**
 * APR-09: POST workflows/{workflow}/publish: the draft becomes the live
 * version once it validates. `core.workflow.publish` at the flow's company.
 */
class PublishWorkflowRequest extends WorkflowRequest
{
    protected ?string $action = 'publish';
}
