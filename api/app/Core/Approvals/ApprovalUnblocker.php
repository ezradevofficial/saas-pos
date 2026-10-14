<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * APR-07, RBAC-04: a request blocked because nobody could approve its
 * step (`no_approver`) is routed again when a role assignment is added at
 * a place that covers it (the assignment's company, branch or location;
 * a tenant-wide one covers every request). The step is resolved as when it
 * started (ApprovalRouting::assignStep): the new holder gets it when the
 * step's approver rule now finds them, and is told (APR-03). Requests that
 * stay blocked stay as they were.
 */
class ApprovalUnblocker
{
    public function __construct(
        private readonly ApprovalRouting $routing,
        private readonly ApprovalNotices $notices,
    ) {}

    public function afterAssignment(RoleAssignment $assignment): int
    {
        $column = match ($assignment->scope_type) {
            Scope::COMPANY => 'company_id',
            Scope::BRANCH => 'branch_id',
            Scope::LOCATION => 'location_id',
            default => null,
        };

        $ids = ApprovalRequest::query()
            ->where('status', ApprovalRequest::PENDING)
            ->where('blocked_reason', ApprovalRequest::BLOCKED_NO_APPROVER)
            ->when($column !== null, fn ($q) => $q->where($column, $assignment->scope_id))
            ->orderBy('received_at')->pluck('id');

        $unblocked = 0;

        foreach ($ids as $id) {
            $unblocked += DB::connection(TenantContext::CONNECTION)->transaction(function () use ($id) {
                $request = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();

                // Still blocked, and nobody holds the step (an admin may have reassigned it meanwhile).
                if ($request === null || ! $request->isPending() || $request->blocked_reason !== ApprovalRequest::BLOCKED_NO_APPROVER
                    || $request->assignments()->where('step', $request->step)->where('status', ApprovalAssignment::PENDING)->exists()) {
                    return 0;
                }

                $users = $this->routing->assignStep($request, $this->routing->subject($request));

                if ($users === []) {
                    return 0;
                }

                $this->notices->requested($request, $users);

                return 1;
            });
        }

        return $unblocked;
    }
}
