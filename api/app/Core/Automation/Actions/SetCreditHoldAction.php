<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Capabilities\HoldsCredit;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * AUTO-03 "set credit hold": `{"type": "set_credit_hold", "hold": true,
 * "reason": "Invoice 30 days overdue"}` (false lifts it). Offered for
 * types that implement HoldsCredit (the party credit type of task 5).
 * Lifting a hold needs the type's release permission (on save and on
 * every run, as the rule's user at the document's scope).
 */
class SetCreditHoldAction implements AutomationAction
{
    public const KEY = 'set_credit_hold';

    public function key(): string
    {
        return self::KEY;
    }

    public function needsDocument(): bool
    {
        return true;
    }

    public function validate(array $action, RuleContext $rule): array
    {
        if (! $rule->type instanceof HoldsCredit) {
            return [__('automation.validation.capability_missing', ['action' => __('automation.actions.'.self::KEY.'.label')])];
        }

        $problems = [];

        if (! is_bool($action['hold'] ?? null)) {
            $problems[] = __('automation.validation.credit_hold');
        }

        if (! is_string($action['reason'] ?? null) || trim($action['reason']) === '' || mb_strlen($action['reason']) > 255) {
            $problems[] = __('automation.validation.credit_reason');
        }

        if (array_diff(array_keys($action), ['type', 'hold', 'reason']) !== []) {
            $problems[] = __('automation.validation.action_extra');
        }

        return $problems;
    }

    public function requiredPermissions(array $action, DocumentType $type): array
    {
        $release = ($action['hold'] ?? true) === false && $type instanceof HoldsCredit ? [$type->releaseHoldPermission()] : [];

        return [$type->actPermission(), ...$release];
    }

    public function describe(array $action, AutomationContext $context): string
    {
        return __('automation.actions.set_credit_hold.describe_'.(($action['hold'] ?? true) ? 'on' : 'off'), ['reason' => (string) ($action['reason'] ?? '')]);
    }

    public function run(array $action, AutomationContext $context): array
    {
        $documentId = $context->requireDocument();

        if (! $context->type instanceof HoldsCredit) {
            throw new ActionFailed(__('automation.errors.capability_missing'));
        }

        $hold = (bool) ($action['hold'] ?? true);

        if (! $hold && ($context->actor === null || ! $context->actor->can($context->type->releaseHoldPermission(), $context->scope->scope()))) {
            throw new ActionFailed(__('automation.errors.release_forbidden'));
        }

        $context->type->setCreditHold($documentId, $hold, (string) ($action['reason'] ?? ''), $context->actor);

        return ['hold' => (bool) ($action['hold'] ?? true)];
    }
}
