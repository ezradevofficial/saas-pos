<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Capabilities\Capabilities;
use App\Core\Automation\Runtime\FieldText;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;

/**
 * AUTO-03 "create document": a draft of another (or the same) document
 * type, through the target type's createDraft() (WF-07), in the
 * triggering document's scope (the rule's company for a schedule):
 *
 *   {"type": "create_document", "target": "procurement.requisition",
 *    "mapping": {"amount": "total"},           target field <= this document's field (same field type)
 *    "values": {"note": "Reorder"}}            target field <= a fixed value
 *
 * The rule's author needs the target type's act permission.
 */
class CreateDocumentAction implements AutomationAction
{
    public const KEY = 'create_document';

    public function __construct(
        private readonly DocumentTypeRegistry $types,
        private readonly ConditionEvaluator $conditions,
        private readonly FieldText $text,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function needsDocument(): bool
    {
        return false;
    }

    public function validate(array $action, RuleContext $rule): array
    {
        $target = is_string($action['target'] ?? null) ? $this->types->find($action['target']) : null;

        if ($target === null || ! Capabilities::createsDrafts($target)) {
            return [__('automation.validation.create_target', ['type' => is_string($action['target'] ?? null) ? $action['target'] : ''])];
        }

        $problems = [];
        $mapping = $action['mapping'] ?? [];
        $values = $action['values'] ?? [];
        $targetFields = $target->fieldsByName();
        $sourceFields = $rule->type->fieldsByName();

        if (! is_array($mapping) || ($mapping !== [] && array_is_list($mapping)) || ! is_array($values) || ($values !== [] && array_is_list($values))) {
            return [__('automation.validation.create_fields')];
        }

        if ($mapping !== [] && ! $rule->hasDocument) {
            $problems[] = __('automation.validation.create_mapping_without_document');
        }

        foreach ($mapping as $to => $from) {
            $a = $targetFields[$to] ?? null;
            $b = is_string($from) ? ($sourceFields[$from] ?? null) : null;

            if ($a === null || $b === null || $a->type !== $b->type || $a->reference !== $b->reference) {
                $problems[] = __('automation.validation.create_mapping', ['field' => (string) $to]);
            }
        }

        foreach ($values as $to => $value) {
            $field = $targetFields[$to] ?? null;

            if ($field === null || array_key_exists($to, $mapping)
                || $this->conditions->validate(['field' => $field->name, 'op' => 'eq', 'value' => $value], [$field->name => $field]) !== []) {
                $problems[] = __('automation.validation.create_value', ['field' => (string) $to]);
            }
        }

        if (array_diff(array_keys($action), ['type', 'target', 'mapping', 'values']) !== []) {
            $problems[] = __('automation.validation.action_extra');
        }

        return $problems;
    }

    public function requiredPermissions(array $action, DocumentType $type): array
    {
        $target = is_string($action['target'] ?? null) ? $this->types->find($action['target']) : null;

        return $target === null ? [] : [$target->actPermission()];
    }

    public function describe(array $action, AutomationContext $context): string
    {
        $target = $this->types->find((string) ($action['target'] ?? ''));

        if ($target === null) {
            return __('automation.actions.create_document.describe', ['document' => (string) ($action['target'] ?? ''), 'fields' => '']);
        }

        $parts = [];

        foreach ($this->values($action, $context) as $name => $value) {
            $field = $target->field($name);

            if ($field !== null) {
                $text = $this->text->format($field, $value, app()->getLocale(), $context->timezone);
                $parts[] = $field->displayLabel().' = '.($text === '' ? __('automation.values.empty') : $text);
            }
        }

        return __('automation.actions.create_document.describe', ['document' => __($target->label()), 'fields' => implode('; ', $parts)]);
    }

    public function run(array $action, AutomationContext $context): array
    {
        $target = $this->types->find((string) ($action['target'] ?? ''));

        if ($target === null || ! Capabilities::createsDrafts($target)) {
            throw new ActionFailed(__('automation.errors.target_unavailable', ['type' => (string) ($action['target'] ?? '')]));
        }

        $documentId = $target->createDraft($this->values($action, $context), $context->scope, $context->actor);

        return ['target' => $target->key(), 'document_id' => $documentId];
    }

    /** @return array<string, mixed> the new draft's values */
    private function values(array $action, AutomationContext $context): array
    {
        $values = [];

        foreach ((array) ($action['mapping'] ?? []) as $to => $from) {
            $values[$to] = $context->documentId === null ? null : ($context->visibleValues()[$from] ?? null);
        }

        foreach ((array) ($action['values'] ?? []) as $to => $value) {
            $values[$to] = $value;
        }

        return $values;
    }
}
