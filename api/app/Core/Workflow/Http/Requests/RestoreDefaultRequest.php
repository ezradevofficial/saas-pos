<?php

namespace App\Core\Workflow\Http\Requests;

/**
 * WF-02: POST workflows/{workflow}/restore-default: the type's default
 * flow becomes the draft again (publish it to apply).
 * `core.workflow.edit` at the flow's company.
 */
class RestoreDefaultRequest extends WorkflowRequest
{
    protected ?string $action = 'edit';
}
