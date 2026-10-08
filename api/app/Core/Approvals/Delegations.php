<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalDelegation;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly ScopeResolver $scopes,
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

    /**
     * Who $user may delegate to: active colleagues holding a role at a place
     * overlapping one of $user's own (the same place, above or beneath it);
     * a user with a tenant-wide role overlaps everyone. At most 50, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function candidates(User $user, string $search = ''): array
    {
        $search = trim($search);

        return $this->candidateQuery($user)?->when($search !== '', fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name')->orderBy('id')->limit(50)->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all() ?? [];
    }

    /** L1: whether $id is one of $user's delegation candidates (not only the first 50). */
    public function isCandidate(User $user, string $id): bool
    {
        return $this->candidateQuery($user)?->whereKey($id)->exists() ?? false;
    }

    /** @return Builder<User>|null null: nobody */
    private function candidateQuery(User $user): ?Builder
    {
        $own = RoleAssignment::query()->where('user_id', $user->id)->get(['scope_type', 'scope_id']);
        $places = [];
        $everyone = false;

        foreach ($own as $assignment) {
            if ($assignment->scope_type === Scope::TENANT) {
                $everyone = true;

                break;
            }

            array_push($places, ...($this->scopes->chainOf(Scope::of($assignment->scope_type, $assignment->scope_id)) ?? []));

            if ($assignment->scope_type === Scope::COMPANY) {
                $branches = Branch::query()->where('company_id', $assignment->scope_id)->pluck('id')->all();
                array_push($places, ...array_map(fn ($id) => 'branch:'.$id, $branches));
                array_push($places, ...Location::query()->whereIn('branch_id', $branches)->pluck('id')->map(fn ($id) => 'location:'.$id)->all());
            } elseif ($assignment->scope_type === Scope::BRANCH) {
                array_push($places, ...Location::query()->where('branch_id', $assignment->scope_id)->pluck('id')->map(fn ($id) => 'location:'.$id)->all());
            }
        }

        $places = array_values(array_unique($places));

        if (! $everyone && $places === []) {
            return null;
        }

        $holders = RoleAssignment::query()->select('user_id')->when(! $everyone, fn ($q) => $q->where(function ($w) use ($places) {
            foreach ($places as $place) {
                [$type, $id] = explode(':', $place, 2);
                $w->orWhere(fn ($x) => $x->where('scope_type', $type)->where('scope_id', $id));
            }
        }));

        return User::query()->where('status', User::STATUS_ACTIVE)->whereKeyNot($user->id)->whereIn('id', $holders);
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
