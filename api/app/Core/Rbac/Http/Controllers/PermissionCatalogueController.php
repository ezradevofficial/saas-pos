<?php

namespace App\Core\Rbac\Http\Controllers;

use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

/**
 * GET permissions (RBAC-01): the catalogue of the tenant's active modules,
 * grouped `{module: {label, resources: {resource: {label, actions: [...]}}}}`,
 * with translated labels where they exist (else the raw name). For role
 * editors: needs `core.role.view`.
 */
class PermissionCatalogueController
{
    public function __invoke(Request $request, PermissionRegistry $registry, ModuleRegistry $modules): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Role::class), 403);

        $active = array_flip($modules->active());
        $data = [];

        foreach ($registry->all() as $permission) {
            ['name' => $name, 'module' => $module, 'resource' => $resource, 'action' => $action] = $permission;

            if (! isset($active[$module])) {
                continue;
            }

            $data[$module] ??= ['label' => self::label("rbac.catalogue.modules.{$module}", $module), 'resources' => []];
            $data[$module]['resources'][$resource] ??= [
                'label' => self::label("rbac.catalogue.resources.{$module}.{$resource}", $resource),
                'actions' => [],
            ];
            $data[$module]['resources'][$resource]['actions'][] = [
                'action' => $action,
                'name' => $name,
                'label' => self::label("rbac.catalogue.actions.{$action}", $action),
            ];
        }

        return new JsonResponse(['data' => (object) $data]);
    }

    private static function label(string $key, string $fallback): string
    {
        return Lang::has($key) ? __($key) : $fallback;
    }
}
