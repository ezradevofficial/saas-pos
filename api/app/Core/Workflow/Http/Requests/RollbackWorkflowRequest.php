<?php

namespace App\Core\Workflow\Http\Requests;

/**
 * APR-09, spec 6.4: POST workflows/{workflow}/rollback {version}: publish a
 * copy of an earlier version. `core.workflow.publish` at the flow's company.
 */
class RollbackWorkflowRequest extends WorkflowRequest
{
    protected ?string $action = 'publish';

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }

    public function attributes(): array
    {
        return ['version' => __('workflow.attributes.version')];
    }
}
