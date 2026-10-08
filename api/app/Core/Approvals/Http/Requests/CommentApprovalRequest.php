<?php

namespace App\Core\Approvals\Http\Requests;

/**
 * APR-03: POST approvals/{approval}/comment {comment} (anyone who sees the
 * request) and POST approvals/{approval}/request-info {comment} (an
 * approver asking the requester).
 */
class CommentApprovalRequest extends ApprovalItemRequest
{
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['comment' => __('approvals.attributes.comment')];
    }
}
