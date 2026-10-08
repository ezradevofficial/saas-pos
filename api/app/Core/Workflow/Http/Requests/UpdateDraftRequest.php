<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Definitions\GraphValidator;

/**
 * WF-03..WF-09: PUT workflows/{workflow}/draft {graph}: save the draft
 * (created when there is none). A graph that cannot be read is refused
 * (422 with `problems`); one that is readable but not yet publishable is
 * saved, and the answer lists what blocks publishing.
 * `core.workflow.edit` at the flow's company.
 */
class UpdateDraftRequest extends WorkflowRequest
{
    protected ?string $action = 'edit';

    public function rules(): array
    {
        return [
            'graph' => ['required', 'array'],
            'graph.nodes' => ['present', 'array', 'max:'.GraphValidator::MAX_NODES],
            'graph.edges' => ['present', 'array', 'max:'.GraphValidator::MAX_EDGES],
        ];
    }

    /** The graph as sent (validated() keeps only the keys named in rules()). */
    public function graph(): array
    {
        return (array) $this->input('graph');
    }
}
