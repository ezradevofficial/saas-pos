<?php

namespace App\Core\Rbac\Http\Middleware;

use App\Core\Http\ApiException;
use App\Core\Rbac\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:{name}`: refuse the route unless the current tenant has the
 * module active, 403 `module_inactive` (RBAC-08). Use after `tenant`.
 */
class EnsureModuleActive
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->modules->isActive($module)) {
            throw new ApiException(403, 'module_inactive', __('rbac.errors.module_inactive'));
        }

        return $next($request);
    }
}
