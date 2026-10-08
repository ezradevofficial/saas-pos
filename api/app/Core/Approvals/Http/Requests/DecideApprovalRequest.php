<?php

namespace App\Core\Approvals\Http\Requests;

/**
 * APR-03: POST approvals/{approval}/approve|reject {comment?}. A rejection
 * always needs a reason; an approval only when the step requires one
 * (checked by ApprovalDecisions).
 */
class DecideApprovalRequest extends ApprovalItemRequest
{
    public function rules(): array
    {
        return [
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['comment' => __('approvals.attributes.comment')];
    }
}
