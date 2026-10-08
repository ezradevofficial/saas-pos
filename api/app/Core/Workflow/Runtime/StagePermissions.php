<?php

namespace App\Core\Workflow\Runtime;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * WF-08: who may move a document into or out of a stage. A stage naming
 * roles (`enter_roles`, `exit_roles`) admits users holding one of them at
 * a scope covering the document (RBAC-04: a company role covers its
 * branches and locations). A stage naming none admits users holding the
 * document type's act permission there.
 */
class StagePermissions
{
    public function __construct(
        private readonly RoleRefs $roles,
        private readonly ScopeResolver $resolver,
        private readonly TenantContext $tenants,
    ) {}

    /** @param 'enter'|'exit' $direction */
    public function allows(User $user, array $node, string $direction, DocumentScope $scope, DocumentType $type): bool
    {
        $roleIds = $this->roleIds($node, $direction);

        if ($roleIds === null) {
            return $user->can($type->actPermission(), $scope->scope());
        }

        return array_intersect($this->resolver->roleIds($user, $scope->scope()), $roleIds) !== [];
    }

    /**
     * The roles a stage names for $direction (null: none named, the act
     * permission applies). Named roles that no longer exist are left out,
     * so a stage naming only archived roles admits nobody.
     *
     * @return list<string>|null
     */
    public function roleIds(array $node, string $direction): ?array
    {
        $refs = $node[$direction.'_roles'] ?? [];

        if (! is_array($refs) || $refs === []) {
            return null;
        }

        return $this->roles->resolve($refs);
    }

    /**
     * WF-10 "holder": the stage's exit roles and the active users holding
     * them at the document's scope (at most $limit), or the permission when
     * the stage names no roles.
     *
     * @return array{roles: list<array{id: string, name: string}>, users: list<array{id: string, name: string}>, permission: ?string}
     */
    public function holders(array $node, DocumentScope $scope, DocumentType $type, ?array $roleIds = null, ?array $userIds = null, int $limit = 20): array
    {
        $roleIds ??= $this->roleIds($node, 'exit');

        if ($roleIds === null && $userIds === null) {
            return ['roles' => [], 'users' => [], 'permission' => $type->actPermission()];
        }

        $roleIds ??= [];
        $chain = $scope->chain($this->tenants->require());

        $users = RoleAssignment::query()
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->whereIn('role_assignments.role_id', $roleIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $roleIds)
            ->where('users.status', 'active')
            ->where(function ($q) use ($chain) {
                foreach ($chain as $link) {
                    [$type, $id] = explode(':', $link, 2);
                    $q->orWhere(fn ($w) => $w->where('role_assignments.scope_type', $type)->where('role_assignments.scope_id', $id));
                }
            })
            ->toBase()
            ->distinct()
            ->orderBy('users.name')
            ->limit($limit)
            ->get(['users.id', 'users.name'])
            ->map(fn (object $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();

        if ($userIds !== null && $userIds !== []) {
            $named = User::query()->whereKey($userIds)->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all();
            $users = array_values(collect([...$named, ...$users])->unique('id')->all());
        }

        return ['roles' => $this->roles->describe($roleIds), 'users' => $users, 'permission' => null];
    }
}
