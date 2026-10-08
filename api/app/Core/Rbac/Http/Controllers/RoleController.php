<?php

namespace App\Core\Rbac\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Rbac\Http\Requests\CopyRoleRequest;
use App\Core\Rbac\Http\Requests\ListRolesRequest;
use App\Core\Rbac\Http\Requests\RoleActionRequest;
use App\Core\Rbac\Http\Requests\StoreRoleRequest;
use App\Core\Rbac\Http\Requests\UpdateRoleRequest;
use App\Core\Rbac\Http\Resources\RoleResource;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\RoleManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RBAC-02, RBAC-12: the tenant's roles. Listed from any scope holding
 * `core.role.view`; managed at tenant scope. System roles are copied, never
 * edited or archived.
 */
class RoleController
{
    public function __construct(private readonly RoleManager $manager) {}

    public function index(ListRolesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applySearch($request->applyStatus(Role::query()), ['name' => 'name', 'description' => 'description']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return RoleResource::collection($query->with('permissions:id,name')->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->manager->create($request->user(), $request->validated());

        return RoleResource::make($role->load('permissions'))->response()->setStatusCode(201);
    }

    public function show(RoleActionRequest $request, Role $role): RoleResource
    {
        abort_unless($request->user()->can('view', $role), 403);

        return RoleResource::make($role->load('permissions'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        return RoleResource::make($this->manager->update($request->user(), $role, $request->validated())->load('permissions'));
    }

    public function copy(CopyRoleRequest $request, Role $role): JsonResponse
    {
        $copy = $this->manager->copy($request->user(), $role, $request->validated('name'));

        return RoleResource::make($copy->load('permissions'))->response()->setStatusCode(201);
    }

    public function archive(RoleActionRequest $request, Role $role): RoleResource
    {
        abort_unless($request->user()->can('archive', $role), 403);

        return RoleResource::make($this->manager->archive($role)->load('permissions'));
    }
}
