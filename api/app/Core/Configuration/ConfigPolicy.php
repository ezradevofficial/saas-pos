<?php

namespace App\Core\Configuration;

use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see, edit and publish configuration (LAY-06, RBAC-04, RBAC-09),
 * through the kind's permissions (`core.config.*` by default):
 *
 * - a company, branch or location document: the permission at that place
 *   (held there or above it);
 * - a tenant document: seen with any of the kind's permissions anywhere,
 *   edited and published with the permission at tenant scope;
 * - a role document: the permission at tenant scope (roles are tenant-wide);
 * - a user document (a personal copy): its user, when they hold any of the
 *   kind's permissions anywhere (or the kind is `personal`, LAY-01, LAY-04),
 *   may see, edit and publish it; anyone else needs the permission at
 *   tenant scope.
 *
 * "View" is met by any of the kind's three permissions.
 */
class ConfigPolicy
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    /** Any of the kind's permissions anywhere in the tenant. */
    public function anywhere(User $user, ConfigKind $kind): bool
    {
        foreach ($kind->permissions as $permission) {
            if ($this->resolver->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, ConfigKind $kind, ConfigDocument $document): bool
    {
        return $document->tenant_id === $user->tenant_id
            && $document->kind === $kind->key
            && $this->allows($user, $kind, 'view', $document->scope_type, $document->scope_id);
    }

    /**
     * Whether $user may $action a document of $kind at the scope ($id
     * already checked to be a row of the tenant).
     *
     * @param  'view'|'edit'|'publish'  $action
     */
    public function allows(User $user, ConfigKind $kind, string $action, string $type, ?string $id): bool
    {
        $permissions = $action === 'view' ? array_values($kind->permissions) : [$kind->permission($action)];
        $at = fn (Scope $scope) => $this->any($user, $permissions, $scope);

        return match ($type) {
            ConfigDocument::TENANT => $action === 'view' ? $this->anywhere($user, $kind) : $at(Scope::tenant()),
            ConfigDocument::COMPANY, ConfigDocument::BRANCH, ConfigDocument::LOCATION => $id !== null && $at(Scope::of($type, $id)),
            ConfigDocument::ROLE => $at(Scope::tenant()),
            ConfigDocument::USER => ($id === $user->id && ($kind->personal || $this->anywhere($user, $kind))) || $at(Scope::tenant()),
            default => false,
        };
    }

    /** RBAC-04: restrict a query of $kind's documents to those $user sees. */
    public function visible(Builder $query, User $user, ConfigKind $kind): Builder
    {
        $ids = [ConfigDocument::COMPANY => [], ConfigDocument::BRANCH => [], ConfigDocument::LOCATION => []];

        foreach ($kind->permissions as $permission) {
            if ($this->resolver->can($user, $permission, Scope::tenant())) {
                return $query;
            }

            $visible = $this->resolver->visibleIds($user, $permission);

            foreach (array_keys($ids) as $level) {
                array_push($ids[$level], ...$visible->idsFor($level));
            }
        }

        $anywhere = $this->anywhere($user, $kind);

        return $query->where(function (Builder $q) use ($ids, $anywhere, $user, $kind) {
            $q->whereRaw('false');

            if ($anywhere) {
                $q->orWhere('scope_type', ConfigDocument::TENANT);
            }

            if ($anywhere || $kind->personal) {
                $q->orWhere(fn (Builder $own) => $own->where('scope_type', ConfigDocument::USER)->where('scope_id', $user->id));
            }

            foreach ($ids as $level => $levelIds) {
                if ($levelIds !== []) {
                    $q->orWhere(fn (Builder $place) => $place->where('scope_type', $level)->whereIn('scope_id', array_values(array_unique($levelIds))));
                }
            }
        });
    }

    /** @param list<string> $permissions */
    private function any(User $user, array $permissions, Scope $scope): bool
    {
        foreach ($permissions as $permission) {
            if ($this->resolver->can($user, $permission, $scope)) {
                return true;
            }
        }

        return false;
    }
}
