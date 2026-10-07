<?php

namespace App\Core\Rbac\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Grants;
use App\Core\Rbac\Http\Requests\StoreAssignmentRequest;
use App\Core\Rbac\Http\Resources\AssignmentResource;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\RoleManager;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * RBAC-04, RBAC-10, RBAC-12: a user's role assignments. Granting is checked
 * by Grants (scope and no privilege escalation); removing never takes away
 * the last Owner. Both are audited (`rbac.assignment.*`).
 */
class AssignmentController
{
    public function __construct(
        private readonly RoleManager $manager,
        private readonly Grants $grants,
        private readonly ScopeResolver $resolver,
        private readonly ScopeNames $scopeNames,
    ) {}

    public function index(Request $request, User $user): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('view', $user), 404);

        $visible = $this->resolver->visibleIds($request->user(), 'core.user.view');
        $assignments = $user->assignments()->with(['role', 'creator'])->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (RoleAssignment $a) => ScopeNames::covers($visible, $a->scope_type, $a->scope_id))
            ->values();
        $this->scopeNames->attach($assignments);

        return AssignmentResource::collection($assignments);
    }

    public function store(StoreAssignmentRequest $request, User $user): JsonResponse
    {
        $scope = $this->grants->scope($request->validated('scope_type'), $request->validated('scope_id'));
        $role = $this->grants->role($request->validated('role_id'));

        $assignment = $this->manager->assign($request->user(), $user, $role, $scope)->load(['role', 'creator']);
        $this->scopeNames->attach([$assignment]);

        return AssignmentResource::make($assignment)->response()->setStatusCode(201);
    }

    public function destroy(Request $request, RoleAssignment $assignment): Response
    {
        // RBAC-04: an assignment of a user out of scope is not found.
        abort_unless($request->user()->can('view', $assignment->user), 404);

        $this->manager->unassign($request->user(), $assignment);

        return response()->noContent();
    }
}
