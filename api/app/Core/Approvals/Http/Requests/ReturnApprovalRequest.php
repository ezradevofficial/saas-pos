<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Workflow\Definitions\GraphValidator;

/**
 * APR-03: POST approvals/{approval}/return {node, reason}: send the
 * document back for changes to a stage it passed (`return_targets` of the
 * detail), with a reason.
 */
class ReturnApprovalRequest extends ApprovalItemRequest
{
    public function rules(): array
    {
        return [
            'node' => ['required', 'string', 'regex:'.GraphValidator::ID],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['node' => __('approvals.attributes.node'), 'reason' => __('approvals.attributes.reason')];
    }
}
