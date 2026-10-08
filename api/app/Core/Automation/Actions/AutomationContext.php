<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * A rule's actions running (or described in test mode) for one trigger:
 * the rule, the run (null in test mode), the document (null for a
 * schedule) with its scope and field values, the user the rule acts as
 * (its last editor; null when no longer active) and the time zone dates
 * are read in (the company's).
 */
final class AutomationContext
{
    /** @param array<string, mixed> $values the document's field values */
    public function __construct(
        public readonly AutomationRule $rule,
        public readonly ?AutomationRun $run,
        public readonly DocumentType $type,
        public readonly ?string $documentId,
        public readonly DocumentScope $scope,
        public readonly array $values,
        public readonly ?User $actor,
        public readonly string $timezone,
        public readonly string $locale = 'en',
    ) {}

    public function requireDocument(): string
    {
        return $this->documentId ?? throw new ActionFailed(__('automation.errors.no_document'));
    }

    public function requireActor(): User
    {
        return $this->actor ?? throw new ActionFailed(__('automation.errors.no_actor'));
    }
}
