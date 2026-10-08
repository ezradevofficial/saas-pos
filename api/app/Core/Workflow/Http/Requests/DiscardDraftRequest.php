<?php

namespace App\Core\Workflow\Http\Requests;

/**
 * WF-02: POST workflows/{workflow}/discard-draft: the draft is archived as
 * discarded (never deleted, APR-09) and the live version stays.
 * `core.workflow.edit` at the flow's company.
 */
class DiscardDraftRequest extends WorkflowRequest
{
    protected ?string $action = 'edit';
}
