<?php

namespace App\Core\Manual;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Support\Collection;

/**
 * The manual topics one user may read in the app (RBAC-08, RBAC-04): the
 * topic's module is core or one the tenant has active, and the user holds at
 * least one of the topic's permissions at any scope. A topic is about doing
 * something, so its `.view` permissions count only when it lists nothing
 * else: a cashier who can see the tills does not get "Add and pair a till".
 * A topic with no permissions is for every signed-in user.
 */
class VisibleTopics
{
    public function __construct(
        private readonly TopicLibrary $library,
        private readonly ModuleRegistry $modules,
        private readonly ScopeResolver $resolver,
    ) {}

    /** @return Collection<int, Topic> */
    public function for(User $user, string $locale): Collection
    {
        return $this->library->all($locale)
            ->filter(fn (Topic $topic) => $this->allows($user, $topic))
            ->values();
    }

    public function find(User $user, string $locale, string $id): ?Topic
    {
        return $this->for($user, $locale)->first(fn (Topic $topic) => $topic->id === $id);
    }

    private function allows(User $user, Topic $topic): bool
    {
        if (! $this->modules->isActive($topic->module)) {
            return false;
        }

        if ($topic->permissions === []) {
            return true;
        }

        $actions = array_values(array_filter($topic->permissions, fn (string $p) => ! str_ends_with($p, '.view')));

        foreach ($actions ?: $topic->permissions as $permission) {
            if ($this->resolver->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }
}
