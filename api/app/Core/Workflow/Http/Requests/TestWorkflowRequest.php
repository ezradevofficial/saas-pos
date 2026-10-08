<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Definitions\GraphValidator;

/**
 * Spec 6.4 "test with a sample": POST workflows/{workflow}/test
 * {values, outcomes?, version?, graph?}: walk the graph sent, else the
 * given version, else the draft (else the published version) with sample
 * field values, and answer the path taken and why. Nothing is written.
 * Reading the flow is enough.
 */
class TestWorkflowRequest extends WorkflowRequest
{
    public function rules(): array
    {
        return [
            'values' => ['present', 'array'],
            'outcomes' => ['sometimes', 'array'],
            'outcomes.*' => ['string', 'in:approved,rejected'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['required_with:graph', 'array', 'max:'.GraphValidator::MAX_NODES],
            'graph.edges' => ['required_with:graph', 'array', 'max:'.GraphValidator::MAX_EDGES],
        ];
    }

    /** @return array<string, mixed> the sample values as sent */
    public function values(): array
    {
        return (array) $this->input('values', []);
    }

    public function graph(): ?array
    {
        return $this->has('graph') ? (array) $this->input('graph') : null;
    }
}
