<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Approvals\ApprovalAccess;

/**
 * APR-06: GET approvals/{approval}/reassign-candidates, for people who may
 * reassign the request (`core.approval.reassign` at the document's place).
 */
class ReassignCandidatesRequest extends ApprovalItemRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return app(ApprovalAccess::class)->mayReassign($this->user(), $this->approval());
    }
}
