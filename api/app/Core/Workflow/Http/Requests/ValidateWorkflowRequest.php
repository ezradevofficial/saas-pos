<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Definitions\GraphValidator;

/**
 * Spec 6.4: POST workflows/{workflow}/validate {graph?}: what would block
 * publishing the draft, or the graph sent (not saved). Reading the flow is
 * enough; nothing is written.
 */
class ValidateWorkflowRequest extends WorkflowRequest
{
    public function rules(): array
    {
        return [
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['required_with:graph', 'array', 'max:'.GraphValidator::MAX_NODES],
            'graph.edges' => ['required_with:graph', 'array', 'max:'.GraphValidator::MAX_EDGES],
        ];
    }

    public function graph(): ?array
    {
        return $this->has('graph') ? (array) $this->input('graph') : null;
    }
}
