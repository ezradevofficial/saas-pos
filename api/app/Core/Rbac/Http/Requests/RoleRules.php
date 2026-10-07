<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Rbac\PermissionRegistry;
use Illuminate\Validation\Rule;

/** RBAC-02: role fields. Names are unique among the tenant's active roles (RLS scopes the rule). */
final class RoleRules
{
    public static function name(?string $ignoreId = null): array
    {
        return [
            'string', 'max:100',
            Rule::unique('roles', 'name')->whereNull('archived_at')->ignore($ignoreId),
        ];
    }

    public static function rules(?string $ignoreId = null): array
    {
        return [
            'name' => ['required', ...self::name($ignoreId)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'requires_two_factor' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(app(PermissionRegistry::class)->all()->keys()->all())],
        ];
    }
}
