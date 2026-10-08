<?php

namespace App\Core\Rbac\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Grants;
use App\Core\Rbac\Http\Requests\ListAssignmentsRequest;
use App\Core\Rbac\Http\Requests\RemoveAssignmentRequest;
use App\Core\Rbac\Http\Requests\StoreAssignmentRequest;
use App\Core\Rbac\Http\Resources\AssignmentResource;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\RoleManager;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function index(ListAssignmentsRequest $request, User $user, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $visible = $this->resolver->visibleIds($request->user(), 'core.user.view');
        // Only the roles held where the reader can see (RBAC-04).
        $query = ScopeNames::constrain(RoleAssignment::query()->where('user_id', $user->id), $visible);
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->whereHas('role', fn (Builder $q) => $q->where('name', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        // Every assignment unless paging is asked for (ListAssignmentsRequest).
        $assignments = $request->wantsPage()
            ? $query->with(['role', 'creator'])->paginate($request->perPage())->withQueryString()
            : $query->with(['role', 'creator'])->get();
        $this->scopeNames->attach($request->wantsPage() ? $assignments->getCollection() : $assignments);

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

    public function destroy(RemoveAssignmentRequest $request, RoleAssignment $assignment): Response
    {
        // RBAC-04: an assignment of a user out of scope is not found.
        abort_unless($request->user()->can('view', $assignment->user), 404);

        $this->manager->unassign($request->user(), $assignment);

        return response()->noContent();
    }
}
