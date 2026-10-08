<?php

namespace App\Core\Workflow\Definitions;

use App\Core\Rbac\Models\Role;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Roles a stage names for who may move documents in and out (WF-08): a
 * tenant role's id, or `template:{key}` for the tenant's system role from
 * that template (RBAC-03), so default flows shipped as data can name roles
 * every tenant has. Archived roles grant nothing. Reads run under the
 * tenant's row-level security, so another tenant's role id is unknown.
 */
class RoleRefs
{
    public const TEMPLATE_PREFIX = 'template:';

    /** @var array<string, ?string> "tenant|ref" => role id */
    private array $resolved = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The active role ids the refs name; unknown refs are left out.
     *
     * @param  list<mixed>  $refs
     * @return list<string>
     */
    public function resolve(array $refs): array
    {
        $ids = [];

        foreach ($refs as $ref) {
            if (is_string($ref) && ($id = $this->one($ref)) !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Refs that name no active role of the tenant.
     *
     * @param  list<mixed>  $refs
     * @return list<string>
     */
    public function unknown(array $refs): array
    {
        return array_values(array_map(
            fn ($ref) => is_string($ref) ? $ref : json_encode($ref),
            array_filter($refs, fn ($ref) => ! is_string($ref) || $this->one($ref) === null),
        ));
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string, name: string}>
     */
    public function describe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Role::query()->whereKey($ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])->values()->all();
    }

    private function one(string $ref): ?string
    {
        $key = $this->tenants->require().'|'.$ref;

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $query = Role::query()->whereNull('archived_at');

        if (str_starts_with($ref, self::TEMPLATE_PREFIX)) {
            $query->where('is_system', true)->where('template_key', substr($ref, strlen(self::TEMPLATE_PREFIX)));
        } elseif (Str::isUuid($ref)) {
            $query->whereKey($ref);
        } else {
            return $this->resolved[$key] = null;
        }

        return $this->resolved[$key] = $query->value('id');
    }
}
