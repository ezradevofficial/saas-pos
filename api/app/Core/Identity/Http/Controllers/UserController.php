<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Http\Requests\UpdateUserRequest;
use App\Core\Identity\Http\Requests\UserListRequest;
use App\Core\Identity\Http\Resources\UserAdminResource;
use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Users of the tenant, as administrators see them (AUTH-13, RBAC-04): only
 * users with an assignment inside the actor's scope (users without any are
 * seen from tenant scope only). Users are deactivated, never deleted; their
 * history stays.
 */
class UserController
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly ScopeNames $scopeNames,
        private readonly OwnerGuard $owners,
        private readonly Auditor $auditor,
    ) {}

    public function index(UserListRequest $request): AnonymousResourceCollection
    {
        $visible = $this->resolver->visibleIds($request->user(), 'core.user.view');
        $query = User::query();

        if (! $visible->all) {
            $query->whereHas('assignments', fn (Builder $q) => ScopeNames::constrain($q, $visible));
        }

        $users = $request->applyStatus($query)
            ->with(['assignments' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'assignments.role', 'assignments.creator'])
            ->orderBy('name')->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        // Only the roles held where the actor can see.
        $users->getCollection()->each(fn (User $user) => $user->setRelation(
            'assignments',
            $user->assignments->filter(fn ($a) => ScopeNames::covers($visible, $a->scope_type, $a->scope_id))->values(),
        ));
        $this->scopeNames->attach($users->getCollection()->flatMap->assignments);

        return UserAdminResource::collection($users);
    }

    public function show(Request $request, User $user): UserAdminResource
    {
        abort_unless($request->user()->can('view', $user), 404);

        return UserAdminResource::make($this->withRoles($request, $user));
    }

    public function update(UpdateUserRequest $request, User $user): UserAdminResource
    {
        $user->fill($request->validated())->save();

        return UserAdminResource::make($this->withRoles($request, $user));
    }

    /** AUTH-13: deactivated, every token revoked, history kept; never the last Owner (RBAC-10). */
    public function deactivate(Request $request, User $user): UserAdminResource
    {
        $this->authorize($request, 'deactivate', $user);

        $this->owners->protect($user, function () use ($user) {
            $user->refresh();

            if ($user->status === User::STATUS_DEACTIVATED) {
                return;
            }

            $before = $user->status;
            $user->forceFill(['status' => User::STATUS_DEACTIVATED])->saveQuietly();
            $user->tokens()->delete();
            $this->auditor->record('core.user.deactivate', $user, ['status' => $before], ['status' => $user->status]);
        });

        return UserAdminResource::make($this->withRoles($request, $user));
    }

    /** Back to active; only a user who verified a contact (422 `contact_unverified`). */
    public function reactivate(Request $request, User $user): UserAdminResource
    {
        $this->authorize($request, 'reactivate', $user);

        if (! $user->hasVerifiedContact()) {
            throw new ApiException(422, 'contact_unverified', __('auth.users.contact_unverified'));
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            DB::transaction(function () use ($user) {
                $before = $user->status;
                $user->forceFill(['status' => User::STATUS_ACTIVE, 'failed_sign_ins' => 0, 'locked_until' => null])->saveQuietly();
                $this->auditor->record('core.user.reactivate', $user, ['status' => $before], ['status' => $user->status]);
            });
        }

        return UserAdminResource::make($this->withRoles($request, $user));
    }

    /** AUTH-09: end every session of the user. */
    public function signOutEverywhere(Request $request, User $user): Response
    {
        $this->authorize($request, 'signOutEverywhere', $user);

        DB::transaction(function () use ($user) {
            $count = $user->tokens()->delete();
            $this->auditor->record('core.user.sign_out_everywhere', $user, null, ['tokens' => $count]);
        });

        return response()->noContent();
    }

    private function authorize(Request $request, string $ability, User $user): void
    {
        abort_unless($request->user()->can('view', $user), 404);
        abort_unless($request->user()->can($ability, $user), 403);
    }

    private function withRoles(Request $request, User $user): User
    {
        $visible = $this->resolver->visibleIds($request->user(), 'core.user.view');
        $user->load(['assignments' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'assignments.role', 'assignments.creator']);
        $user->setRelation('assignments', $user->assignments
            ->filter(fn ($a) => ScopeNames::covers($visible, $a->scope_type, $a->scope_id))->values());
        $this->scopeNames->attach($user->assignments);

        return $user;
    }
}
