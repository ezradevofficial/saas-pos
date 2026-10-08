<?php

namespace App\Core\Workflow\Http\Requests;

/**
 * WF-11: POST document-workflows/{type}/{document}/cancel {reason}: cancel
 * the document's flow; documents it created are kept or cancelled as the
 * flow says.
 */
class CancelDocumentRequest extends DocumentWorkflowRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    public function attributes(): array
    {
        return ['reason' => __('workflow.attributes.reason')];
    }
}
