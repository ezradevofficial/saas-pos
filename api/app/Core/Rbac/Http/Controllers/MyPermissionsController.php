<?php

namespace App\Core\Rbac\Http\Controllers;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET me/permissions: what the signed-in user may do and where, and the
 * tenant's active modules (RBAC-09: drives the UI; the API checks again),
 * and whether they are an Owner (RBAC-10: the usage banner, LIM-02).
 */
class MyPermissionsController
{
    public function __invoke(Request $request, ScopeResolver $resolver, ModuleRegistry $modules, OwnerGuard $owners): JsonResponse
    {
        return new JsonResponse([
            'permissions' => $resolver->permissionsOf($request->user()),
            'modules' => $modules->active(),
            'owner' => $owners->isOwner($request->user()),
        ]);
    }
}
