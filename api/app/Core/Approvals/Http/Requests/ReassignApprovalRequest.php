<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Approvals\ApprovalAccess;
use Illuminate\Validation\Rule;

/**
 * APR-06: POST approvals/{approval}/reassign {from_user_id, to_user_id,
 * reason?}: needs `core.approval.reassign` at the document's place.
 * `from_user_id` is a pending approver of the current step (null for a
 * blocked request nobody holds); `to_user_id` an active user of the
 * tenant who is not the requester (checked by ApprovalActions).
 */
class ReassignApprovalRequest extends ApprovalItemRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return app(ApprovalAccess::class)->mayReassign($this->user(), $this->approval());
    }

    public function rules(): array
    {
        return [
            'from_user_id' => ['present', 'nullable', 'uuid', Rule::exists('users', 'id')],
            'to_user_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'from_user_id' => __('approvals.attributes.from_user'),
            'to_user_id' => __('approvals.attributes.to_user'),
            'reason' => __('approvals.attributes.reason'),
        ];
    }
}
