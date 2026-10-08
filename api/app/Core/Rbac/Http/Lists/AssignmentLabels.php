<?php

namespace App\Core\Rbac\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Rbac\Scope;

/**
 * "Role at where" in an export (EXP-01), as the web app shows roles: the
 * tenant scope is "Whole organisation"; another scope is its name, or its
 * level alone when the name is unknown or out of the reader's scope
 * (RBAC-04).
 */
final class AssignmentLabels
{
    public static function scope(ExportValues $values, string $type, ?string $name): string
    {
        if ($type === Scope::TENANT || $name === null || $name === '') {
            return (string) $values->enum('core.assignment.scope_types', $type);
        }

        return $name;
    }

    public static function roleAt(ExportValues $values, ?string $role, string $type, ?string $name): string
    {
        return __('core.assignment.role_at', ['role' => $role ?? '', 'scope' => self::scope($values, $type, $name)]);
    }
}
