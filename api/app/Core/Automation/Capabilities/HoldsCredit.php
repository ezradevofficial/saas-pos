<?php

namespace App\Core\Automation\Capabilities;

use App\Core\Identity\Models\User;

/**
 * AUTO-03 "set credit hold": a document type whose documents are (or
 * belong to) a customer that can be put on or taken off credit hold, e.g.
 * the party credit type (Phase 3 task 5). The module applies it and
 * records it in its own history; the reason is the rule's text.
 *
 * Putting a customer on hold only tightens credit. Lifting it (hold false)
 * loosens it, so it is never automatic by default: the rule's user must
 * hold releaseHoldPermission() at the document's scope (checked when the
 * rule is saved and again on every run), and a type whose release goes
 * through approval must refuse it in setCreditHold() (throw) rather than
 * release it.
 */
interface HoldsCredit
{
    public function setCreditHold(string $documentId, bool $hold, string $reason, ?User $by): void;

    /** The permission needed to lift a hold (e.g. `core.party.release_credit_hold`). */
    public function releaseHoldPermission(): string;
}
