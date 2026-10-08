<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Definitions\GraphValidator;

/**
 * WF-11: POST document-workflows/{type}/{document}/return {node, reason}:
 * send the document back to a stage it passed, with a reason.
 */
class ReturnDocumentRequest extends DocumentWorkflowRequest
{
    public function rules(): array
    {
        return [
            'node' => ['required', 'string', 'regex:'.GraphValidator::ID],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['node' => __('workflow.attributes.node'), 'reason' => __('workflow.attributes.reason')];
    }
}
