<?php

namespace App\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Platform\SupportAccess\SupportAccess;
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
        // RBAC-13 (review M6): a support principal holds no role; it gets the most restrictive
        // answer instead: every field any role's rule hides stays hidden, every restricted one
        // read-only, and personal data stays hidden unless the grant includes sensitive data.
        if ($user->support_grant_id !== null) {
            return $this->forSupport($user, $resource);
        }

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

    /** @return array{hidden: list<string>, readonly: list<string>} */
    private function forSupport(User $user, string $resource): array
    {
        $rules = FieldRule::query()->where('resource', $resource)->get();
        $hidden = $rules->where('mode', FieldRule::HIDDEN)->pluck('field')->unique()->all();

        if (! app(SupportAccess::class)->grantOf($user)?->include_sensitive) {
            $hidden = [...$hidden, ...(SupportAccess::SENSITIVE_FIELDS[$resource] ?? [])];
        }

        $hidden = array_values(array_unique($hidden));
        $readonly = array_values(array_diff($rules->pluck('field')->unique()->all(), $hidden));
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
