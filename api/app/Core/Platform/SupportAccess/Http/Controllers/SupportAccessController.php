<?php

namespace App\Core\Platform\SupportAccess\Http\Controllers;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Platform\SupportAccess\Http\Requests\ApproveSupportAccessRequest;
use App\Core\Platform\SupportAccess\Http\Requests\DecideSupportAccessRequest;
use App\Core\Platform\SupportAccess\Http\Requests\SupportAccessViewRequest;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Platform\SupportAccess\SupportAccess;
use Illuminate\Http\JsonResponse;

/**
 * RBAC-13b, RBAC-13e: Settings → Security → Support access. The tenant
 * sees every request, approves or denies the pending ones (choosing
 * whether sensitive data is included), revokes an active one, and sees
 * what each session did (its entries in the tenant's audit).
 */
class SupportAccessController
{
    public function __construct(private readonly SupportAccess $support) {}

    public function index(SupportAccessViewRequest $request): JsonResponse
    {
        $grants = SupportAccessGrant::query()->orderByDesc('requested_at')->limit(200)->get();

        return response()->json(['data' => $this->present($grants->all())]);
    }

    /** "Support access active": the sessions open now (the shell's bar). */
    public function active(SupportAccessViewRequest $request): JsonResponse
    {
        $grants = SupportAccessGrant::query()->where('status', SupportAccessGrant::APPROVED)->where('expires_at', '>', now())->orderBy('expires_at')->get();

        return response()->json(['data' => $this->present($grants->all())]);
    }

    public function approve(ApproveSupportAccessRequest $request, SupportAccessGrant $supportAccessGrant): JsonResponse
    {
        $grant = $this->support->approve($supportAccessGrant, $request->actor(), $request->boolean('include_sensitive'), $request->validated('mode'), $request->validated('note'));

        return response()->json(['data' => $this->present([$grant])[0]]);
    }

    public function deny(DecideSupportAccessRequest $request, SupportAccessGrant $supportAccessGrant): JsonResponse
    {
        $grant = $this->support->deny($supportAccessGrant, $request->actor(), $request->validated('note'));

        return response()->json(['data' => $this->present([$grant])[0]]);
    }

    public function revoke(DecideSupportAccessRequest $request, SupportAccessGrant $supportAccessGrant): JsonResponse
    {
        $grant = $this->support->revoke($supportAccessGrant, $request->actor());

        return response()->json(['data' => $this->present([$grant])[0]]);
    }

    /** RBAC-13e: what the session did, from the tenant's own audit (newest first). */
    public function activity(SupportAccessViewRequest $request, SupportAccessGrant $supportAccessGrant): JsonResponse
    {
        $entries = AuditEntry::query()
            ->where('auditable_type', $supportAccessGrant->getMorphClass())
            ->where('auditable_id', $supportAccessGrant->id)
            ->orderByDesc('seq')->limit(500)->get(['seq', 'action', 'occurred_at', 'user_id', 'before', 'after']);

        return response()->json(['data' => $entries->map(fn (AuditEntry $e) => [
            'seq' => $e->seq,
            'action' => $e->action,
            'occurred_at' => $e->occurred_at->toIso8601String(),
            'by_principal' => $e->user_id !== null && $e->user_id === $supportAccessGrant->principal_user_id,
            'method' => $e->after['method'] ?? null,
            'route' => $e->after['route'] ?? null,
            'path' => $e->after['path'] ?? null,
            'status' => $e->after['status'] ?? null,
        ])->all()]);
    }

    /**
     * @param  list<SupportAccessGrant>  $grants
     * @return list<array<string, mixed>>
     */
    private function present(array $grants): array
    {
        $deciders = User::query()->whereIn('id', array_values(array_filter(array_merge(
            array_map(fn (SupportAccessGrant $g) => $g->decided_by, $grants),
            array_map(fn (SupportAccessGrant $g) => $g->revoked_by, $grants),
        ))))->pluck('name', 'id');
        // A write is logged when it starts and when it ends (review M8): count each request once.
        $counts = AuditEntry::query()->where('action', 'core.support_session.request')->whereRaw("(after->>'status') is distinct from 'started'")
            ->whereIn('auditable_id', array_map(fn (SupportAccessGrant $g) => $g->id, $grants))
            ->groupBy('auditable_id')->selectRaw('auditable_id, count(*) as n')->pluck('n', 'auditable_id');

        return array_map(fn (SupportAccessGrant $g) => [
            'id' => $g->id,
            'staff_name' => $g->staff_name,
            'staff_email' => $g->staff_email,
            'reason' => $g->reason,
            'mode' => $g->mode,
            'hours' => $g->hours,
            'include_sensitive' => $g->include_sensitive,
            'status' => $g->effectiveStatus(),
            'active' => $g->isActive(),
            'requested_at' => $g->requested_at->toIso8601String(),
            'decided_by' => $g->decided_by === null ? null : ['id' => $g->decided_by, 'name' => $deciders[$g->decided_by] ?? null],
            'decided_at' => $g->decided_at?->toIso8601String(),
            'decision_note' => $g->decision_note,
            'expires_at' => $g->expires_at?->toIso8601String(),
            'revoked_by' => $g->revoked_by === null ? null : ['id' => $g->revoked_by, 'name' => $deciders[$g->revoked_by] ?? null],
            'ended_at' => $g->ended_at?->toIso8601String(),
            'end_reason' => $g->end_reason,
            'session_started_at' => $g->session_started_at?->toIso8601String(),
            'requests' => (int) ($counts[$g->id] ?? 0),
        ], $grants);
    }
}
