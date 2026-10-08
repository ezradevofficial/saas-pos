<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ScopeResolver;
use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use Illuminate\Support\Str;

/**
 * Who a `notify` action node writes to (NOT-02 through the workflow):
 * its `to` lists `role:<template key or role id>` (everyone holding that
 * role at a scope covering the document, RBAC-04, checked by
 * ScopeResolver) and `user:<user id>` (that active user). Reads run under
 * the tenant's row-level security, so another tenant's role or user is
 * never found.
 */
class NotifyRecipients
{
    public function __construct(
        private readonly RoleRefs $roles,
        private readonly ScopeResolver $resolver,
    ) {}

    /** @return list<string> the `to` entries, whether given as one string or a list */
    public static function entries(array $config): array
    {
        $to = $config['to'] ?? [];

        return array_values(array_filter(is_array($to) ? $to : [$to], 'is_string'));
    }

    /** The role ref RoleRefs understands, or null when the entry is not a role. */
    public static function roleRef(string $entry): ?string
    {
        if (! str_starts_with($entry, 'role:')) {
            return null;
        }

        $ref = substr($entry, 5);

        return Str::isUuid($ref) || str_starts_with($ref, RoleRefs::TEMPLATE_PREFIX) ? $ref : RoleRefs::TEMPLATE_PREFIX.$ref;
    }

    /**
     * Entries that are malformed or name no role or user of the tenant.
     *
     * @return list<string>
     */
    public function unknown(array $config): array
    {
        $unknown = [];

        foreach (self::entries($config) as $entry) {
            $role = self::roleRef($entry);
            $user = str_starts_with($entry, 'user:') ? substr($entry, 5) : null;

            $known = match (true) {
                $role !== null => $this->roles->unknown([$role]) === [],
                $user !== null => Str::isUuid($user) && User::query()->whereKey($user)->exists(),
                default => false,
            };

            if (! $known) {
                $unknown[] = $entry;
            }
        }

        return $unknown;
    }

    /** @return list<string> ids of the active users to notify about a document at $scope */
    public function resolve(array $config, DocumentScope $scope): array
    {
        $ids = [];
        $roleIds = [];

        foreach (self::entries($config) as $entry) {
            if (($role = self::roleRef($entry)) !== null) {
                array_push($roleIds, ...$this->roles->resolve([$role]));
            } elseif (str_starts_with($entry, 'user:') && Str::isUuid(substr($entry, 5))) {
                $ids[] = substr($entry, 5);
            }
        }

        if ($roleIds !== []) {
            $candidates = User::query()->where('status', User::STATUS_ACTIVE)
                ->whereIn('id', RoleAssignment::query()->whereIn('role_id', $roleIds)->select('user_id'))
                ->get();

            foreach ($candidates as $user) {
                if (array_intersect($this->resolver->roleIds($user, $scope->scope()), $roleIds) !== []) {
                    $ids[] = $user->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
