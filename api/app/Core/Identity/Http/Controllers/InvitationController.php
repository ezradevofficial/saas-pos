<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Identity\Http\Requests\StoreInvitationRequest;
use App\Core\Identity\Http\Resources\InvitationResource;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Services\Invitations;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * AUTH-05: invitations, seen by holders of `core.user.invite` for the
 * scopes they reach: an invitation is visible when one of its assignments
 * is within the actor's scope (tenant-wide holders see all), and revoked
 * only by an actor whose scope covers all of its assignments.
 */
class InvitationController
{
    public function __construct(
        private readonly Invitations $invitations,
        private readonly ScopeResolver $resolver,
    ) {}

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('core.user.invite'), 403);

        $query = $this->visible(Invitation::query(), $this->resolver->visibleIds($request->user(), 'core.user.invite'));

        return InvitationResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $invitation = $this->invitations->invite($request->user(), $request->validated());

        return InvitationResource::make($invitation)->response()->setStatusCode(201);
    }

    public function revoke(Request $request, string $invitation): InvitationResource
    {
        abort_unless($request->user()->can('core.user.invite'), 403);

        $visible = $this->resolver->visibleIds($request->user(), 'core.user.invite');
        $model = $this->visible(Invitation::query(), $visible)->whereKey($invitation)->firstOrFail();

        // Seen through one assignment, revoked only by covering them all.
        abort_unless(collect($model->assignments)->every(
            fn (array $a) => ScopeNames::covers($visible, $a['scope_type'], $a['scope_id']),
        ), 403);

        return InvitationResource::make($this->invitations->revoke($model));
    }

    /** Invitations with at least one assignment inside $visible. */
    private function visible(Builder $query, VisibleScope $visible): Builder
    {
        if ($visible->all) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($visible) {
            $q->whereRaw('false');

            foreach ([Scope::COMPANY, Scope::BRANCH, Scope::LOCATION] as $level) {
                foreach ($visible->idsFor($level) as $id) {
                    $q->orWhereRaw('assignments @> ?::jsonb', [json_encode([['scope_type' => $level, 'scope_id' => $id]])]);
                }
            }
        });
    }
}
