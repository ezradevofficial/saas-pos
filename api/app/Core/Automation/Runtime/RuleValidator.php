<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Actions\AutomationActions;
use App\Core\Automation\Actions\RuleContext;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\WorkflowAccess;

/**
 * AUTO-01..AUTO-03: checks a rule's trigger, conditions (the workflow
 * condition JSON, AUTO-02) and actions against its document type, and
 * that the person saving it may do what it does: see the type's documents
 * at the rule's scope, and hold each action's extra permissions there (a
 * rule never acts beyond its author), and that the trigger, conditions and
 * copied fields read no field the author's field rules hide (RBAC-05).
 *
 * Problems: `path` (trigger, conditions, actions.N) and a translated
 * `message`.
 */
class RuleValidator
{
    public const MAX_ACTIONS = 20;

    public function __construct(
        private readonly Triggers $triggers,
        private readonly ConditionEvaluator $conditions,
        private readonly AutomationActions $actions,
        private readonly WorkflowAccess $access,
        private readonly RuleTimezone $timezones,
        private readonly FieldVisibility $visibility,
    ) {}

    /**
     * @param  array{trigger?: mixed, conditions?: mixed, actions?: mixed}  $definition
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $definition, RuleContext $rule): array
    {
        $problems = [];
        $add = function (string $path, string $message) use (&$problems) {
            $problems[] = ['path' => $path, 'message' => $message];
        };
        $type = $rule->type;
        $scope = new DocumentScope($rule->companyId);
        $trigger = $definition['trigger'] ?? null;

        foreach ($this->triggers->validate($trigger, $type) as $message) {
            $add('trigger', $message);
        }

        if (is_array($trigger) && ($trigger['type'] ?? null) === Triggers::SCHEDULE && $this->timezones->forCompany($rule->companyId) === null) {
            $add('company_id', __('automation.validation.schedule_company'));
        }

        $conditions = $definition['conditions'] ?? null;

        if ($conditions !== null && $conditions !== []) {
            if (! is_array($conditions)) {
                $add('conditions', __('automation.validation.conditions', ['problem' => __('workflow.conditions.invalid.invalid_shape')]));
            } elseif (! $rule->hasDocument) {
                $add('conditions', __('automation.validation.conditions_without_document'));
            } else {
                foreach ($this->conditions->validate($conditions, $type->fieldsByName()) as $problem) {
                    $add('conditions', __('automation.validation.conditions', ['problem' => __('workflow.conditions.invalid.'.$problem['code'], $problem['params'])]));
                }
            }
        }

        $actions = $definition['actions'] ?? null;

        if (! is_array($actions) || ! array_is_list($actions) || $actions === [] || count($actions) > self::MAX_ACTIONS) {
            $add('actions', __('automation.validation.actions', ['max' => self::MAX_ACTIONS]));
            $actions = [];
        }

        $editor = $rule->editor;

        if ($editor !== null && ! $this->access->seesDocument($editor, $type, $scope)) {
            $add('document_type', __('automation.validation.cannot_see_type', ['type' => __($type->label())]));
        }

        // RBAC-05: like a hidden sort or filter, a rule may not read a field hidden from its author.
        $hidden = $this->visibility->hidden($editor, $type);

        if ($hidden !== []) {
            $read = [
                'trigger' => is_array($trigger) ? array_values(array_filter([...(array) ($trigger['fields'] ?? []), $trigger['field'] ?? null], 'is_string')) : [],
                'conditions' => is_array($conditions) ? $this->conditions->fieldsOf($conditions) : [],
            ];

            foreach ($actions as $i => $action) {
                if (is_array($action) && ($action['type'] ?? null) === 'create_document' && is_array($action['mapping'] ?? null)) {
                    $read["actions.{$i}"] = array_values(array_filter($action['mapping'], 'is_string'));
                }
            }

            foreach ($read as $path => $fields) {
                if (($blocked = array_values(array_intersect($fields, $hidden))) !== []) {
                    $add($path, __('automation.validation.hidden_fields', ['fields' => implode(', ', $blocked)]));
                }
            }
        }

        foreach ($actions as $i => $action) {
            $path = "actions.{$i}";
            $handler = is_array($action) && is_string($action['type'] ?? null) ? $this->actions->find($action['type']) : null;

            if ($handler === null) {
                $add($path, __('automation.validation.unknown_action', ['action' => is_array($action) && is_scalar($action['type'] ?? null) ? (string) $action['type'] : '']));

                continue;
            }

            if ($handler->needsDocument() && ! $rule->hasDocument) {
                $add($path, __('automation.validation.action_needs_document', ['action' => __('automation.actions.'.$handler->key().'.label')]));

                continue;
            }

            if (! is_string($action['id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $action['id']) !== 1) {
                $add($path, __('automation.validation.action_id'));
            }

            // The stable id is the list's, not the action's setting.
            foreach ($handler->validate(array_diff_key($action, ['id' => true]), $rule) as $message) {
                $add($path, $message);
            }

            if ($editor !== null) {
                foreach ($handler->requiredPermissions($action, $type) as $permission) {
                    if (! $editor->can($permission, $scope->scope())) {
                        $add($path, __('automation.validation.permission', ['action' => __('automation.actions.'.$handler->key().'.label')]));

                        break;
                    }
                }
            }
        }

        return $problems;
    }
}
