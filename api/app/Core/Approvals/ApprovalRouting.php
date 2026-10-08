<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Approvals\Resolvers\ApprovalSubject;
use App\Core\Approvals\Resolvers\ApproverDirectory;
use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\WorkflowVersion;

/**
 * Who a request's current step goes to (APR-01, APR-02, APR-05, APR-07).
 *
 * The step's approver is resolved to candidates; the requester (whoever
 * started the flow and, when the type knows it, the document's creator)
 * and inactive users are removed. When nobody remains, the step moves to
 * the next eligible approver per the node's `escalation.to` (next_level when unset): next_level
 * (the managers of the nearest place above the document's that has an
 * eligible one), a role or a user. When still nobody remains the request
 * is blocked (`blocked_reason` no_approver, shown in the inbox and the
 * flow status) until an admin reassigns it (APR-06) or the final timeout
 * decides it (APR-05).
 */
class ApprovalRouting
{
    public function __construct(
        private readonly ApproverResolvers $resolvers,
        private readonly ApproverDirectory $directory,
        private readonly ScopeResolver $scopes,
        private readonly DocumentTypeRegistry $types,
        private readonly RoleRefs $roles,
        private readonly TenantContext $tenants,
        private readonly ApprovalLog $log,
    ) {}

    public function subject(ApprovalRequest $request, ?array $node = null): ApprovalSubject
    {
        $type = $this->types->get($request->document_type);
        $scope = $type->scope($request->document_id) ?? ApprovalAccess::scope($request);
        $node ??= WorkflowVersion::query()->find($request->version_id)?->flow()->node($request->node_id) ?? [];

        return new ApprovalSubject(
            $type,
            $request->document_id,
            $scope,
            $this->scopes->chainOf($scope->scope()) ?? $scope->chain($this->tenants->require()),
            $node,
        );
    }

    /** @return list<string> users who may never approve the request (APR-07) */
    public function excluded(ApprovalRequest $request): array
    {
        $type = $this->types->find($request->document_type);

        return array_values(array_unique(array_filter([
            $request->requester_id,
            $type?->requesterId($request->document_id),
        ])));
    }

    /**
     * Resolve and assign the current step. Returns the users assigned
     * (none: the request is blocked).
     *
     * @return list<string>
     */
    public function assignStep(ApprovalRequest $request, ApprovalSubject $subject): array
    {
        $approver = $request->config['chain'][$request->step] ?? ['type' => ApprovalConfig::STAGE_ROLES];
        $resolver = $this->resolvers->find((string) ($approver['type'] ?? ''));
        $users = $this->eligible($request, $resolver === null ? [] : $resolver->resolve($approver, $subject));
        $source = ApprovalAssignment::SOURCE_RESOLVED;

        if ($users === []) {
            // APR-07: a node without an escalation target still moves to the next level's manager.
            $users = $this->escalationTargets($request, $subject, 'next_level');
            $source = ApprovalAssignment::SOURCE_FALLBACK;
        }

        if ($users === []) {
            $request->blocked_reason = ApprovalRequest::BLOCKED_NO_APPROVER;
            $request->save();
            $this->log->action($request, 'blocked', null, null, ['step' => $request->step, 'reason' => ApprovalRequest::BLOCKED_NO_APPROVER]);

            return [];
        }

        $request->blocked_reason = null;
        $request->save();
        $this->assign($request, $users, $source);

        return $users;
    }

    /**
     * The next eligible approvers per the node's `escalation.to`, leaving
     * out anyone already on the request: next_level moves `escalated_depth`
     * up (unsaved) to the level found.
     *
     * @return list<string>
     */
    public function escalationTargets(ApprovalRequest $request, ApprovalSubject $subject, ?string $default = null): array
    {
        $to = $request->config['escalation']['to'] ?? null;
        $already = $request->assignments()->whereIn('status', [ApprovalAssignment::PENDING, ApprovalAssignment::APPROVED, ApprovalAssignment::REJECTED])
            ->pluck('user_id')->all();

        switch ($to['type'] ?? $default) {
            case 'next_level':
                for ($depth = max(1, $request->escalated_depth + 1); ($scope = $subject->ancestor($depth)) !== null; $depth++) {
                    $users = $this->eligible($request, $this->directory->managersAt($scope), $already);

                    if ($users !== []) {
                        $request->escalated_depth = $depth;

                        return $users;
                    }
                }

                return [];

            case 'role':
                return is_string($to['role'] ?? null)
                    ? $this->eligible($request, $this->directory->holdersAt($this->roles->resolve([$to['role']]), $subject->chain), $already)
                    : [];

            case 'user':
                return ApprovalConfig::knownUser($to['user_id'] ?? null) ? $this->eligible($request, [(string) $to['user_id']], $already) : [];

            default:
                return [];
        }
    }

    /**
     * @param  list<string>  $userIds
     * @return list<ApprovalAssignment>
     */
    public function assign(ApprovalRequest $request, array $userIds, string $source, array $attributes = []): array
    {
        $created = [];

        foreach ($userIds as $userId) {
            $created[] = ApprovalAssignment::create([
                'request_id' => $request->id,
                'user_id' => $userId,
                'step' => $request->step,
                'source' => $source,
                'status' => ApprovalAssignment::PENDING,
                ...$attributes,
            ]);
        }

        return $created;
    }

    /**
     * Active users among $candidates, without the requester and $also.
     *
     * @param  list<string>  $candidates
     * @param  list<string>  $also
     * @return list<string>
     */
    public function eligible(ApprovalRequest $request, array $candidates, array $also = []): array
    {
        $candidates = array_values(array_diff(array_unique($candidates), $this->excluded($request), $also));

        if ($candidates === []) {
            return [];
        }

        return User::query()->whereKey($candidates)->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Who the escalation goes to, for people ("the next level's manager", a role or user name), or null without one. */
    public function describeEscalation(ApprovalRequest $request): ?string
    {
        $to = $request->config['escalation']['to'] ?? null;

        return match ($to['type'] ?? null) {
            'next_level' => __('approvals.escalation_targets.next_level'),
            'role' => $this->resolvers->find('role')?->describe($to),
            'user' => $this->resolvers->find('user')?->describe($to),
            default => null,
        };
    }
}
