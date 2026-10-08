<?php

namespace App\Core\Automation\Capabilities;

use App\Core\Identity\Models\User;

/**
 * AUTO-03 "update field": a document type that lets automation rules write
 * some of its fields. The type lists the fields automation may write (a
 * subset of fields()); the engine never writes a module's tables itself
 * (architecture rule 1). updateField() runs inside the rule's transaction
 * and the tenant's context; it applies the module's own rules and throws
 * (an ApiException with a translated message) to refuse.
 */
interface UpdatesFields
{
    /** @return list<string> names of fields() automation may write */
    public function writableFields(): array;

    /**
     * Write one field. $value is shaped as FieldDefinition describes (null
     * clears it); $by is the user the rule acts as, null for the system.
     */
    public function updateField(string $documentId, string $field, mixed $value, ?User $by): void;
}
