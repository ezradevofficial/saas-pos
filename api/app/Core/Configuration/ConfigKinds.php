<?php

namespace App\Core\Configuration;

use App\Core\Rbac\ModuleRegistry;

/**
 * The registry of configuration kinds (LAY-06). A module registers its
 * kinds in its provider's boot():
 *
 *   app(ConfigKinds::class)->register(new ConfigKind(
 *       key: 'list_view',
 *       schema: ListViewSchema::SCHEMA,
 *       scopes: ['tenant', 'company', 'role', 'user'],
 *       merger: fn (array $payload) => ...,
 *   ));
 *
 * A kind whose module is not active for the tenant is not found (RBAC-08).
 */
class ConfigKinds
{
    /** @var array<string, ConfigKind> */
    private array $kinds = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** Registers (or replaces) a kind. */
    public function register(ConfigKind $kind): void
    {
        $this->kinds[$kind->key] = $kind;
    }

    /** The kind, when registered and its module is active for the tenant. */
    public function find(string $key): ?ConfigKind
    {
        $kind = $this->kinds[$key] ?? null;

        return $kind !== null && $this->modules->isActive($kind->module) ? $kind : null;
    }

    public function get(string $key): ConfigKind
    {
        return $this->find($key) ?? abort(404);
    }

    /** @return list<string> keys of the active kinds */
    public function keys(): array
    {
        return array_values(array_filter(array_keys($this->kinds), fn (string $key) => $this->find($key) !== null));
    }

    /** @return list<string> every permission name the registered kinds use */
    public function permissionNames(): array
    {
        $names = array_values(ConfigKind::DEFAULT_PERMISSIONS);

        foreach ($this->kinds as $kind) {
            array_push($names, ...array_values($kind->permissions));
        }

        return array_values(array_unique($names));
    }
}
