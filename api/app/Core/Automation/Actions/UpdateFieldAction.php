<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Capabilities\Capabilities;
use App\Core\Automation\Capabilities\UpdatesFields;
use App\Core\Automation\Runtime\FieldText;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * AUTO-03 "update field": `{"type": "update_field", "field": "status",
 * "value": "approved"}` (null clears it). Only fields the type lists as
 * writable by automation (UpdatesFields); the value is checked as a
 * condition value of the field's type. The module writes it.
 */
class UpdateFieldAction implements AutomationAction
{
    public const KEY = 'update_field';

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly FieldText $text,
    ) {}

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
        if (! $rule->type instanceof UpdatesFields) {
            return [__('automation.validation.capability_missing', ['action' => __('automation.actions.'.self::KEY.'.label')])];
        }

        $name = $action['field'] ?? null;
        $field = is_string($name) && in_array($name, Capabilities::writableFields($rule->type), true) ? $rule->type->field($name) : null;

        if ($field === null) {
            return [__('automation.validation.field_not_writable', ['field' => is_string($name) ? $name : ''])];
        }

        if (! array_key_exists('value', $action)
            || ($action['value'] !== null && $this->conditions->validate(['field' => $field->name, 'op' => 'eq', 'value' => $action['value']], [$field->name => $field]) !== [])) {
            return [__('automation.validation.field_value', ['field' => $field->displayLabel()])];
        }

        return array_diff(array_keys($action), ['type', 'field', 'value']) === [] ? [] : [__('automation.validation.action_extra')];
    }

    public function requiredPermissions(array $action, DocumentType $type): array
    {
        return [$type->actPermission()];
    }

    public function describe(array $action, AutomationContext $context): string
    {
        $field = $context->type->field((string) ($action['field'] ?? ''));
        $value = $field === null ? '' : $this->text->format($field, $action['value'] ?? null, app()->getLocale(), $context->timezone);

        return __('automation.actions.update_field.describe', [
            'field' => $field === null ? (string) ($action['field'] ?? '') : $field->displayLabel(),
            'value' => $value === '' ? __('automation.values.empty') : $value,
        ]);
    }

    public function run(array $action, AutomationContext $context): array
    {
        $documentId = $context->requireDocument();
        $field = (string) ($action['field'] ?? '');

        if (! $context->type instanceof UpdatesFields || ! in_array($field, Capabilities::writableFields($context->type), true)) {
            throw new ActionFailed(__('automation.errors.field_not_writable', ['field' => $field]));
        }

        $context->type->updateField($documentId, $field, $action['value'] ?? null, $context->actor);

        return ['field' => $field];
    }
}
