<?php

namespace App\Core\Api;

use App\Core\Api\Http\Middleware\PublicApi;
use App\Core\Api\Models\ApiKey;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Lang;

/**
 * API-02: what a key's scopes allow (docs/adr/020).
 *
 * A scope is `module.resource.read` or `module.resource.write`, for a
 * resource of the permission catalogue (RBAC-01); `write` includes `read`.
 * Scopes limit a key twice: a public route marked `public-api:<resource>`
 * needs the resource's read scope for GET and HEAD and its write scope
 * otherwise, and every permission check of the key's principal
 * (ScopeResolver) needs the scope of the permission's resource: `view*`,
 * `export` and `print` actions read, every other action writes.
 */
class ApiScopes
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** Actions that only read (besides any action starting with `view`). */
    private const READ_ACTIONS = ['export', 'print'];

    /** @var array<string, array<string>|null> principal id => its key's scopes (null: no usable key) */
    private array $memo = [];

    public function __construct(
        private readonly PermissionRegistry $permissions,
        private readonly ModuleRegistry $modules,
        private readonly ApiKeyContext $context,
    ) {}

    /**
     * Every scope a key may be given: two per resource of the permission
     * catalogue, grouped by module.
     *
     * @return list<string>
     */
    public function catalogue(): array
    {
        return $this->permissions->all()
            ->map(fn (array $p) => "{$p['module']}.{$p['resource']}")
            ->unique()
            ->flatMap(fn (string $resource) => ["{$resource}.".self::READ, "{$resource}.".self::WRITE])
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The catalogue for the key form: per active module (RBAC-08), its
     * resources with labels (the permission catalogue's, RBAC-01) and
     * their two scopes.
     *
     * @return list<array{module: string, label: string, resources: list<array{resource: string, label: string, scopes: list<string>}>}>
     */
    public function grouped(): array
    {
        $label = fn (string $key, string $fallback) => Lang::has($key) ? __($key) : $fallback;
        $active = $this->modules->active();
        $groups = [];

        foreach ($this->permissions->all() as ['module' => $module, 'resource' => $resource, 'name' => $name]) {
            if (! $this->modules->permissionActive($name, $active)) {
                continue;
            }

            $groups[$module] ??= ['module' => $module, 'label' => $label("rbac.catalogue.modules.{$module}", $module), 'resources' => []];
            $groups[$module]['resources'][$resource] ??= [
                'resource' => "{$module}.{$resource}",
                'label' => $label("rbac.catalogue.resources.{$module}.{$resource}", $resource),
                'scopes' => ["{$module}.{$resource}.".self::READ, "{$module}.{$resource}.".self::WRITE],
            ];
        }

        return array_values(array_map(fn (array $group) => [...$group, 'resources' => array_values($group['resources'])], $groups));
    }

    public function isScope(string $scope): bool
    {
        return in_array($scope, $this->catalogue(), true);
    }

    /** @param list<string> $scopes */
    public static function allows(array $scopes, string $resource, bool $write): bool
    {
        return in_array("{$resource}.".self::WRITE, $scopes, true)
            || (! $write && in_array("{$resource}.".self::READ, $scopes, true));
    }

    /** Whether `module.resource.action` only reads. */
    public static function permissionReads(string $permission): bool
    {
        $action = substr($permission, strrpos($permission, '.') + 1);

        return str_starts_with($action, 'view') || in_array($action, self::READ_ACTIONS, true);
    }

    /**
     * Whether $principal (a key's service user) may use $permission: its key
     * is usable and its scopes cover the permission's resource. Always
     * true for people.
     */
    public function permits(User $principal, string $permission): bool
    {
        if (! $principal->isServicePrincipal()) {
            return true;
        }

        $scopes = $this->scopesOf($principal);

        if ($scopes === null) {
            return false;
        }

        $resource = substr($permission, 0, (int) strrpos($permission, '.'));

        return self::allows($scopes, $resource, ! self::permissionReads($permission));
    }

    /**
     * The scopes of $principal's key, or null when it has no usable key
     * (revoked or expired: a queued job started with the key stops too).
     *
     * @return list<string>|null
     */
    public function scopesOf(User $principal): ?array
    {
        $key = $this->context->key();

        if ($key !== null && $key->principal_id === $principal->getKey()) {
            return $key->scopeList();
        }

        if (array_key_exists($principal->getKey(), $this->memo)) {
            return $this->memo[$principal->getKey()];
        }

        $stored = ApiKey::query()->where('principal_id', $principal->getKey())->first();

        return $this->memo[$principal->getKey()] = $stored !== null && $stored->isUsable() ? $stored->scopeList() : null;
    }

    /** Forget memoised scopes (a new request or job starts, or a key changed). */
    public function flush(): void
    {
        $this->memo = [];
    }

    /** The resource a public route declares (`public-api:<resource>`), or null for a route keys may not use. */
    public static function resourceOf(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach ([PublicApi::ALIAS.':', PublicApi::class.':'] as $prefix) {
                if (str_starts_with($middleware, $prefix)) {
                    return substr($middleware, strlen($prefix));
                }
            }
        }

        return null;
    }

    /** Whether the module of a scope or resource is active for the tenant (RBAC-08). */
    public function moduleActive(string $scopeOrResource): bool
    {
        return $this->modules->isActive(PermissionRegistry::moduleOf($scopeOrResource));
    }
}
