<?php

namespace App\Core\Automation\Triggers;

/**
 * A trigger that fired for a rule (AUTO-01): the trigger type, what made it
 * fire (kept in the run log: a field and its old and new values, a stage,
 * an occurrence), the document (none for a schedule) and, for date and
 * schedule triggers, the key that keeps one occurrence from running twice;
 * and the document's company (the run log is shown per company).
 */
final class TriggerHit
{
    /** @param array<string, mixed> $details JSON-safe */
    public function __construct(
        public readonly string $type,
        public readonly array $details = [],
        public readonly ?string $documentId = null,
        public readonly ?string $dedupeKey = null,
        public readonly ?string $companyId = null,
    ) {}
}
