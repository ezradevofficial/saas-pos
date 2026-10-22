<?php

namespace App\Core\Marketplace;

use App\Core\Api\ApiScopes;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Support\Facades\Lang;

/**
 * MKT-02: what an app asks for, in plain words for the consent screen
 * ("See items", "See and change customers"), from the permission
 * catalogue's labels (RBAC-01), in the reader's language.
 */
class ScopeDescriptions
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @param  list<string>  $scopes
     * @return list<array{scope: string, module: string, module_label: string, resource: string, resource_label: string, access: string, label: string, active: bool}>
     */
    public function describe(array $scopes): array
    {
        $described = [];

        foreach ($scopes as $scope) {
            $access = substr($scope, (int) strrpos($scope, '.') + 1);
            $resource = substr($scope, 0, (int) strrpos($scope, '.'));
            $module = PermissionRegistry::moduleOf($resource);
            $name = substr($resource, strlen($module) + 1);
            $resourceLabel = self::label("rbac.catalogue.resources.{$module}.{$name}", str_replace('_', ' ', $name));

            $described[] = [
                'scope' => $scope,
                'module' => $module,
                'module_label' => self::label("rbac.catalogue.modules.{$module}", $module),
                'resource' => $resource,
                'resource_label' => $resourceLabel,
                'access' => $access === ApiScopes::WRITE ? ApiScopes::WRITE : ApiScopes::READ,
                'label' => __($access === ApiScopes::WRITE ? 'marketplace.scopes.write' : 'marketplace.scopes.read', ['resource' => mb_strtolower($resourceLabel)]),
                'active' => $this->modules->isActive($module),
            ];
        }

        usort($described, fn (array $a, array $b) => [$a['module'], $a['resource']] <=> [$b['module'], $b['resource']]);

        return $described;
    }

    private static function label(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) __($key) : $fallback;
    }
}
