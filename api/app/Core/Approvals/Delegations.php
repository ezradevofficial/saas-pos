<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalDelegation;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * APR-06: delegation. A user lets another act on their approvals for a
 * date range, for every document type or the listed ones. It works on the
 * delegator's own pending assignments only, while the delegation covers
 * the day (in each request's company time zone) and the node allows
 * delegation: the delegate gains no rights beyond those items (no
 * permission, no other document of the delegator's scope). Delegations
 * do not chain (a delegate's own delegate cannot act on the delegator's
 * items). Decisions record the delegate as the actor, on behalf of the
 * delegator. Created and revoked by the delegator; audited.
 */
class Delegations
{
    public function __construct(
        private readonly ApprovalClock $clock,
        private readonly Auditor $auditor,
        private readonly ApprovalNotices $notices,
    ) {}

    /** @return Collection<int, ApprovalDelegation> delegations to $user not revoked and not ended (by the widest time zone) */
    public function to(User $user): Collection
    {
        return ApprovalDelegation::query()
            ->where('to_user_id', $user->id)
            ->whereNull('revoked_at')
            // A delegation ends while the delegator or the delegate is deactivated.
            ->whereHas('fromUser', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->whereHas('toUser', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->where('ends_on', '>=', CarbonImmutable::now()->subDay()->toDateString())
            ->where('starts_on', '<=', CarbonImmutable::now()->addDay()->toDateString())
            ->get();
    }

    /** The delegation letting $delegate act for $assigneeId on $request now, if any. */
    public function covering(ApprovalRequest $request, string $assigneeId, User $delegate, ?Collection $delegations = null): ?ApprovalDelegation
    {
        if (($request->config['allow_delegation'] ?? true) === false || $assigneeId === $delegate->id) {
            return null;
        }

        $today = $this->clock->today($request->company_id);

        return ($delegations ?? $this->to($delegate))->first(
            fn (ApprovalDelegation $d) => $d->from_user_id === $assigneeId && $d->covers($request->document_type, $today),
        );
    }

    /** @param array{to_user_id: string, starts_on: string, ends_on: string, document_types?: ?list<string>, note?: ?string} $data */
    public function create(User $from, array $data): ApprovalDelegation
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($from, $data) {
            $delegation = ApprovalDelegation::create([
                'from_user_id' => $from->id,
                'to_user_id' => $data['to_user_id'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'document_types' => isset($data['document_types']) && $data['document_types'] !== [] ? array_values($data['document_types']) : null,
                'note' => $data['note'] ?? null,
                'created_by' => $from->id,
            ]);

            $this->auditor->record('core.approval.delegate', $delegation, null, $this->snapshot($delegation));
            $this->notices->delegated($delegation, $from);

            return $delegation;
        });
    }

    public function revoke(ApprovalDelegation $delegation, User $by): ApprovalDelegation
    {
        if ($delegation->revoked_at !== null) {
            return $delegation;
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($delegation, $by) {
            $delegation->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $by->id])->save();
            $this->auditor->record('core.approval.delegation_revoke', $delegation, ['revoked_at' => null], ['revoked_at' => $delegation->revoked_at->toIso8601ZuluString()]);

            return $delegation;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ApprovalDelegation $delegation): array
    {
        return [
            'from_user_id' => $delegation->from_user_id,
            'to_user_id' => $delegation->to_user_id,
            'starts_on' => $delegation->starts_on->format('Y-m-d'),
            'ends_on' => $delegation->ends_on->format('Y-m-d'),
            'document_types' => $delegation->document_types,
        ];
    }
}
