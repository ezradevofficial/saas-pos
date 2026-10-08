<?php

namespace App\Core\Automation\Capabilities;

use App\Core\Identity\Models\User;

/**
 * AUTO-03 "assign user": a document type with fields that hold a person
 * (an owner, an account manager), which automation may set. The engine
 * checks the user is active and may see the document before calling.
 */
interface AssignsUsers
{
    /** @return list<string> names of `reference` fields (to `core.user`) automation may assign */
    public function assignableFields(): array;

    public function assignUser(string $documentId, string $field, string $userId, ?User $by): void;
}
