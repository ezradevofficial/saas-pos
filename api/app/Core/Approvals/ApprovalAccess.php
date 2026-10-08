<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\WorkflowAccess;
use Illuminate\Support\Collection;

/**
 * Who may see and act on an approval request (APR-03, APR-04, APR-06,
 * RBAC-04):
 *
 * - four eyes: one person counts once per request: whoever approved or
 *   rejected any step (themselves or as a delegate) cannot act again;
 * - acting needs only being a pending approver of the current step, or
 *   the active delegate of one (no permission); never the requester
 *   (APR-07, checked by the decision itself);
 * - the request is seen by its approvers (any step, decided or not), the
 *   delegates of its pending approvers, its requester, and holders of
 *   `core.approval.view_all` or `core.approval.reassign` at the document's
 *   place; anyone else gets 404;
 * - reassigning needs `core.approval.reassign` at the document's place.
 *
 * A request of a document type whose module is not active is not found.
 */
class ApprovalAccess
{
    public const VIEW_ALL = 'core.approval.view_all';

    public const REASSIGN = 'core.approval.reassign';

    public function __construct(
        private readonly Delegations $delegations,
        private readonly WorkflowAccess $workflows,
        private readonly DocumentTypeRegistry $types,
    ) {}

    public static function scope(ApprovalRequest $request): DocumentScope
    {
        return new DocumentScope($request->company_id, $request->branch_id, $request->location_id);
    }

    /**
     * The assignment $user acts on now, and whose behalf they act on (null
     * when it is their own).
     *
     * @return array{0: ApprovalAssignment, 1: ?string}|null
     */
    public function acting(ApprovalRequest $request, User $user, ?Collection $delegations = null): ?array
    {
        // Four eyes: one person counts once per request (no second vote as someone's delegate, no second chain step).
        if (! $request->isPending() || $this->hasVoted($request, $user->id)) {
            return null;
        }

        $pending = $request->assignments()->where('step', $request->step)->where('status', ApprovalAssignment::PENDING)
            ->orderBy('created_at')->orderBy('id')->get();
        $own = $pending->firstWhere('user_id', $user->id);

        if ($own !== null) {
            return [$own, null];
        }

        foreach ($pending as $assignment) {
            if ($this->delegations->covering($request, $assignment->user_id, $user, $delegations) !== null) {
                return [$assignment, $assignment->user_id];
            }
        }

        return null;
    }

    /** Whether $userId already approved or rejected any step of the request, for themselves or as a delegate. */
    public function hasVoted(ApprovalRequest $request, string $userId): bool
    {
        return $request->assignments()->where('decided_by', $userId)
            ->whereIn('status', [ApprovalAssignment::APPROVED, ApprovalAssignment::REJECTED])->exists();
    }

    public function sees(User $user, ApprovalRequest $request): bool
    {
        if ($this->types->find($request->document_type) === null) {
            return false;
        }

        return $request->requester_id === $user->id
            || $request->assignments()->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('decided_by', $user->id))->exists()
            || $this->acting($request, $user) !== null
            || $this->oversees($user, $request);
    }

    /** Admin oversight: view_all or reassign at the document's place. */
    public function oversees(User $user, ApprovalRequest $request): bool
    {
        $scope = self::scope($request)->scope();

        return $user->can(self::VIEW_ALL, $scope) || $user->can(self::REASSIGN, $scope);
    }

    public function mayReassign(User $user, ApprovalRequest $request): bool
    {
        return $user->can(self::REASSIGN, self::scope($request)->scope());
    }

    /** WF-10: whether $user may see the document itself (its values, its flow). */
    public function seesDocument(User $user, ApprovalRequest $request): bool
    {
        $type = $this->types->find($request->document_type);
        $scope = $type?->scope($request->document_id);

        return $scope !== null && $this->workflows->seesDocument($user, $type, $scope);
    }
}
