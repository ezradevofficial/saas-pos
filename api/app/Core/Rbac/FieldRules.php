<?php

namespace App\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\FieldRule;

/**
 * Field-level rules (RBAC-05). Rules are per role; a user with several roles
 * gets the most permissive answer: a field is hidden only when every role
 * the user holds (at any scope) hides it, and read-only when every role
 * restricts it (hidden or read-only) but not all hide it.
 */
class FieldRules
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    /** @return array{hidden: list<string>, readonly: list<string>} */
    public function for(User $user, string $resource): array
    {
        $roleIds = $this->resolver->roleIds($user);

        if ($roleIds === []) {
            return ['hidden' => [], 'readonly' => []];
        }

        $hidden = [];
        $readonly = [];

        $byField = FieldRule::whereIn('role_id', $roleIds)->where('resource', $resource)->get()->groupBy('field');

        foreach ($byField as $field => $rules) {
            // Every role must restrict the field.
            if ($rules->pluck('role_id')->unique()->count() < count($roleIds)) {
                continue;
            }

            if ($rules->every(fn (FieldRule $rule) => $rule->mode === FieldRule::HIDDEN)) {
                $hidden[] = $field;
            } else {
                $readonly[] = $field;
            }
        }

        sort($hidden);
        sort($readonly);

        return ['hidden' => $hidden, 'readonly' => $readonly];
    }

    /**
     * $data without the fields hidden from $user (for API resources).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filter(User $user, string $resource, array $data): array
    {
        return array_diff_key($data, array_flip($this->for($user, $resource)['hidden']));
    }
}
