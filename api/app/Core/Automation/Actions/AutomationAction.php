<?php

namespace App\Core\Automation\Actions;

use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * One kind of automation action (AUTO-03), registered in AutomationActions
 * by key. A rule's `actions` is an ordered list of `{"type": key, ...}`;
 * each handler validates its own settings, describes what it would do for
 * test mode (AUTO-04, without any side effect) and does it.
 *
 * run() executes inside the run's transaction and the tenant's context. It
 * throws ActionFailed for a refusal that retrying cannot fix (the message
 * is shown in the run log); any other exception is retried (AUTO-05).
 */
interface AutomationAction
{
    /** The `type` of the action in a rule, e.g. `update_field`. */
    public function key(): string;

    /** Whether the action works on the triggering document (not offered for schedules). */
    public function needsDocument(): bool;

    /**
     * Problems with the action's settings, translated (empty when valid).
     *
     * @param  array<string, mixed>  $action
     * @return list<string>
     */
    public function validate(array $action, RuleContext $rule): array;

    /**
     * Permissions the person saving the rule needs at the rule's scope, on
     * top of `core.automation.edit` (a rule never does what its author may not).
     *
     * @param  array<string, mixed>  $action
     * @return list<string>
     */
    public function requiredPermissions(array $action, DocumentType $type): array;

    /**
     * What it would do, in the reader's language (test mode: no writes, no
     * HTTP, no notifications).
     *
     * @param  array<string, mixed>  $action
     */
    public function describe(array $action, AutomationContext $context): string;

    /**
     * Do it; returns what the run log keeps (JSON-safe, no secrets).
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function run(array $action, AutomationContext $context): array;
}
