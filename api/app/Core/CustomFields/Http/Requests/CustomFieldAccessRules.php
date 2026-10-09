<?php

namespace App\Core\CustomFields\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/**
 * Who works with custom field definitions (CF-01): they are the tenant's,
 * not a company's, so they are seen with `core.custom_field.view` or
 * `core.custom_field.manage` anywhere, and changed with
 * `core.custom_field.manage` at tenant scope (Owner, Admin).
 */
final class CustomFieldAccessRules
{
    public static function views(?User $user): bool
    {
        $resolver = app(ScopeResolver::class);

        return $user !== null && ($resolver->can($user, 'core.custom_field.view') || $resolver->can($user, 'core.custom_field.manage'));
    }

    public static function manages(?User $user): bool
    {
        return $user !== null && app(ScopeResolver::class)->can($user, 'core.custom_field.manage', Scope::tenant());
    }
}
