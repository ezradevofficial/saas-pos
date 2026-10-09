<?php

namespace App\Core\CustomFields;

use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\ScopeResolver;

/**
 * RBAC-05 for custom fields: which of an entity's active fields a user
 * may not see (hidden) or not change (read-only). Two sources, combined:
 *
 * - the definition's `visible_roles` and `editable_roles` (role ids;
 *   empty means everyone who reaches the record). A user sees the field
 *   when any role they hold, at any scope, is listed, as FieldRules
 *   resolves several roles (the most permissive answer);
 * - the field rules (FieldRules) of the entity's resource, naming a field
 *   `custom.<key>`, or `custom` for every custom field.
 *
 * An Owner sees and changes every field. Formula fields are always read-only. Hidden fields are left out of API
 * resources, lists, filters, sorts, exports, history and sync payloads;
 * a write naming a hidden or read-only field is refused (422
 * `field_readonly`), never silently ignored (ADR 011).
 */
class CustomFieldAccess
{
    public const PREFIX = 'custom.';

    public function __construct(
        private readonly CustomFieldDefinitions $definitions,
        private readonly CustomFieldEntities $entities,
        private readonly FieldRules $fieldRules,
        private readonly ScopeResolver $resolver,
    ) {}

    /** @return array{hidden: list<string>, readonly: list<string>} field keys */
    public function for(?User $user, string $entity): array
    {
        if ($user === null) {
            return ['hidden' => [], 'readonly' => []];
        }

        return RequestCache::remember("access.{$user->id}.{$entity}", fn () => $this->compute($user, $entity));
    }

    /** @return array{hidden: list<string>, readonly: list<string>} */
    private function compute(User $user, string $entity): array
    {
        $roleIds = $this->resolver->roleIds($user);
        // RBAC-10: an Owner always sees and changes every field (system roles have no field rules),
        // so no definition can lock the organisation out of its own data.
        $owner = app(OwnerGuard::class)->isOwner($user);
        $resource = $this->entities->find($entity)?->fieldRules();
        $rules = $resource === null ? ['hidden' => [], 'readonly' => []] : $this->fieldRules->for($user, $resource);
        $hidden = [];
        $readonly = [];

        foreach ($this->definitions->active($entity) as $definition) {
            $name = self::PREFIX.$definition->key;
            $sees = $owner || ($definition->visible_roles ?? []) === [] || array_intersect($definition->visible_roles, $roleIds) !== [];

            if (! $sees || in_array($name, $rules['hidden'], true) || in_array('custom', $rules['hidden'], true)) {
                $hidden[] = $definition->key;

                continue;
            }

            $edits = $owner || ($definition->editable_roles ?? []) === [] || array_intersect($definition->editable_roles, $roleIds) !== [];

            if (! $edits || $definition->type === 'formula' || in_array($name, $rules['readonly'], true) || in_array('custom', $rules['readonly'], true)) {
                $readonly[] = $definition->key;
            }
        }

        return ['hidden' => $hidden, 'readonly' => $readonly];
    }

    /** @return list<string> hidden keys as field rule names (`custom.<key>`), for lists and history */
    public function hiddenNames(?User $user, string $entity): array
    {
        return array_map(fn (string $key) => self::PREFIX.$key, $this->for($user, $entity)['hidden']);
    }

    /**
     * Each user's hidden keys among the entity's `show_on_pos` fields, from
     * their role ids (the till applies them per cashier, as it applies field
     * rules; StaffDirectory). Field rules are applied by the caller.
     *
     * @param  array<string, list<string>>  $roleIdsByUser
     * @return array<string, list<string>> user id => `custom.<key>` names
     */
    public function hiddenByRoles(string $entity, array $roleIdsByUser): array
    {
        $fields = $this->definitions->active($entity)->filter(fn (CustomFieldDefinition $d) => $d->show_on_pos && ($d->visible_roles ?? []) !== []);
        $result = [];

        foreach ($roleIdsByUser as $userId => $roleIds) {
            $result[$userId] = $fields
                ->filter(fn (CustomFieldDefinition $d) => array_intersect($d->visible_roles, $roleIds) === [])
                ->map(fn (CustomFieldDefinition $d) => self::PREFIX.$d->key)->values()->all();
        }

        return $result;
    }
}
