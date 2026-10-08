<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Capabilities\HasFieldRules;
use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * RBAC-05 for automation: which of a document type's fields a user may not
 * see under their field rules (the same FieldRules the API resources and
 * list exports use). Webhook payloads, notification placeholders and test
 * mode leave those fields out; rules may not trigger on or test them.
 */
class FieldVisibility
{
    public function __construct(private readonly FieldRules $rules) {}

    public static function resource(DocumentType $type): string
    {
        return $type instanceof HasFieldRules ? $type->fieldRulesResource() : $type->key();
    }

    /** @return list<string> the type's fields hidden from $user (none for no user) */
    public function hidden(?User $user, DocumentType $type): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_intersect($this->rules->for($user, self::resource($type))['hidden'], array_keys($type->fieldsByName())));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    public static function without(array $values, array $hidden): array
    {
        return array_diff_key($values, array_flip($hidden));
    }
}
