<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Definitions\GraphValidator;

/**
 * WF-04, WF-08: POST document-workflows/{type}/{document}/move
 * {node?, outcome?}: complete the stage at `node` (needed when the
 * document is at several, in parallel branches); an approval completes as
 * `approved` (default) or `rejected`. Stage roles and exit/entry rules are
 * checked by the engine: 403 or 422 with the reasons.
 */
class MoveDocumentRequest extends DocumentWorkflowRequest
{
    public function rules(): array
    {
        return [
            'node' => ['sometimes', 'string', 'regex:'.GraphValidator::ID],
            'outcome' => ['sometimes', 'string', 'in:approved,rejected'],
        ];
    }

    public function attributes(): array
    {
        return ['node' => __('workflow.attributes.node'), 'outcome' => __('workflow.attributes.outcome')];
    }
}
