<?php

namespace App\Core\Automation\Capabilities;

use App\Core\Identity\Models\User;

/**
 * AUTO-03 "set credit hold": a document type whose documents are (or
 * belong to) a customer that can be put on or taken off credit hold, e.g.
 * the party credit type (Phase 3 task 5). The module applies it and
 * records it in its own history; the reason is the rule's text.
 */
interface HoldsCredit
{
    public function setCreditHold(string $documentId, bool $hold, string $reason, ?User $by): void;
}
