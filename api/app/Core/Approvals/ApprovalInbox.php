<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalDelegation;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * APR-04: the requests an inbox lists (see ListApprovalsRequest), always
 * limited to document types of the tenant's active modules (RBAC-08).
 */
class ApprovalInbox
{
    public function __construct(
        private readonly DocumentTypeRegistry $types,
        private readonly ScopeResolver $scopes,
    ) {}

    /**
     * @param  array{status?: string, view?: string, type?: string, company?: string, overdue?: bool|string|int, search?: string}  $filters
     * @param  Collection<int, ApprovalDelegation>  $delegations  delegations to $user
     * @return Builder<ApprovalRequest>
     */
    public function query(User $user, array $filters, Collection $delegations): Builder
    {
        $status = $filters['status'] ?? 'waiting';
        $query = ApprovalRequest::query()->whereIn('document_type', $this->types->keys());

        if (($filters['view'] ?? 'mine') === 'all') {
            $this->oversight($query, $user);

            match ($status) {
                'waiting' => $query->where('status', ApprovalRequest::PENDING),
                'decided' => $query->where('status', '!=', ApprovalRequest::PENDING),
                default => null,
            };
        } else {
            $query->where(function (Builder $q) use ($status, $user, $delegations) {
                if ($status !== 'decided') {
                    $q->orWhere(fn (Builder $w) => $this->waiting($w, $user, $delegations));
                }

                if ($status !== 'waiting') {
                    $q->orWhereHas('assignments', fn (Builder $a) => $a->where('decided_by', $user->id)
                        ->whereIn('status', [ApprovalAssignment::APPROVED, ApprovalAssignment::REJECTED, ApprovalAssignment::RETURNED]));
                }
            });
        }

        if (isset($filters['type'])) {
            $query->where('document_type', $filters['type']);
        }

        if (isset($filters['company'])) {
            $query->where('company_id', $filters['company']);
        }

        if (filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('status', ApprovalRequest::PENDING)->whereNotNull('due_at')->where('due_at', '<', CarbonImmutable::now());
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $matchingTypes = array_keys(array_filter(
                $this->types->all(),
                fn ($type) => mb_stripos(__($type->label()), $search) !== false,
            ));

            // RBAC-05: titles a type hides from the user are never matched.
            $titleHidden = array_keys(array_filter($this->types->all(), fn ($type) => in_array('title', $type->hiddenSummaryFields($user), true)));

            $query->where(fn (Builder $q) => $q->where('document_number', 'ilike', $like)
                ->orWhere(fn (Builder $t) => $t->where('document_title', 'ilike', $like)->whereNotIn('document_type', $titleHidden))
                ->orWhere('node_name', 'ilike', $like)
                ->orWhereIn('document_type', $matchingTypes));
        }

        return $query;
    }

    /** Pending, and the user (or someone who delegated to them) is a pending approver of the current step. */
    private function waiting(Builder $query, User $user, Collection $delegations): void
    {
        $query->where('status', ApprovalRequest::PENDING)->where(function (Builder $q) use ($user, $delegations) {
            $q->whereHas('assignments', fn (Builder $a) => $a->where('user_id', $user->id)
                ->where('status', ApprovalAssignment::PENDING)->whereColumn('approval_assignments.step', 'approval_requests.step'));

            foreach ($delegations as $delegation) {
                $q->orWhere(function (Builder $d) use ($delegation) {
                    $d->whereRaw("coalesce((config->>'allow_delegation')::boolean, true)")
                        ->when($delegation->document_types !== null, fn ($w) => $w->whereIn('document_type', $delegation->document_types))
                        ->whereHas('assignments', fn (Builder $a) => $a->where('user_id', $delegation->from_user_id)
                            ->where('status', ApprovalAssignment::PENDING)->whereColumn('approval_assignments.step', 'approval_requests.step'));
                });
            }
        });
    }

    /** `?view=all`: requests at places where the user holds core.approval.view_all (RBAC-04). */
    private function oversight(Builder $query, User $user): void
    {
        $visible = $this->scopes->visibleIds($user, ApprovalAccess::VIEW_ALL);

        if ($visible->all) {
            return;
        }

        $query->where(function (Builder $q) use ($visible) {
            $q->whereRaw('false')
                ->orWhereIn('company_id', $visible->companyIds)
                ->orWhereIn('branch_id', $visible->branchIds)
                ->orWhereIn('location_id', $visible->locationIds);
        });
    }
}
