<?php

namespace App\Core\CustomFields\Entities;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * CF-01: a lookup to a user (an account manager, a buyer). The actor
 * finds themselves, and the users they may see (`core.user.view` at a
 * scope covering one of the user's roles, as UserPolicy; RBAC-04).
 * Searches list active users only.
 */
class UserLookup implements LookupTarget
{
    public const KEY = 'user';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'core.custom_field.entities.user';
    }

    public function labels(User $actor, array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id)));

        return $ids === [] ? [] : $this->visible($actor)->whereKey($ids)->pluck('name', 'id')->all();
    }

    public function search(User $actor, string $search, int $limit): array
    {
        $query = $this->visible($actor)->where('status', User::STATUS_ACTIVE);

        if (trim($search) !== '') {
            $query->where('name', 'ilike', '%'.addcslashes(trim($search), '\\%_').'%');
        }

        return $query->orderBy('name')->orderBy('id')->limit($limit)->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'label' => $user->name])->values()->all();
    }

    private function visible(User $actor): Builder
    {
        $resolver = app(ScopeResolver::class);
        $query = User::query();

        if (! $resolver->can($actor, 'core.user.view')) {
            return $query->whereKey($actor->id);
        }

        $visible = $resolver->visibleIds($actor, 'core.user.view');

        if ($visible->all) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereKey($actor->id)
            ->orWhereHas('assignments', fn (Builder $a) => ScopeNames::constrain($a, $visible)));
    }
}
