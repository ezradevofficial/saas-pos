<?php

namespace App\Core\Automation\Templates;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;

/**
 * AUTO-07, generic: "Alert when a number falls below a level" (the reorder
 * alert's shape) for any type with a number or money field: a threshold
 * trigger going down, notifying the Admin role by default.
 */
class AlertBelowLevel implements RuleTemplate
{
    public function key(): string
    {
        return 'core.alert_below_level';
    }

    public function label(): string
    {
        return 'automation.templates.alert_below_level.label';
    }

    public function description(): string
    {
        return 'automation.templates.alert_below_level.description';
    }

    public function appliesTo(DocumentType $type): bool
    {
        // A threshold fires on record changes (AUTO-01).
        return $type->raisesRecordEvents() && self::fields($type) !== [];
    }

    public function parameters(DocumentType $type): array
    {
        return [
            ['name' => 'field', 'kind' => 'field', 'fields' => self::fields($type), 'default' => self::fields($type)[0] ?? null],
            ['name' => 'value', 'kind' => 'value', 'default' => null],
            ['name' => 'to', 'kind' => 'recipients', 'default' => ['role:admin']],
        ];
    }

    public function build(DocumentType $type, array $params): array
    {
        $field = $params['field'] ?? self::fields($type)[0] ?? null;
        $label = is_string($field) && $type->field($field) !== null ? $type->field($field)->displayLabel() : '';

        return [
            'name' => __('automation.templates.alert_below_level.name', ['field' => $label]),
            'trigger' => ['type' => 'threshold', 'field' => $field, 'value' => $params['value'] ?? null, 'direction' => 'down'],
            'conditions' => null,
            'actions' => [[
                'type' => 'notify',
                'to' => $params['to'] ?? ['role:admin'],
                'subject' => __('automation.templates.alert_below_level.subject', ['field' => $label]),
                'message' => __('automation.templates.alert_below_level.message', ['field' => $label, 'value' => is_string($field) ? '{'.$field.'}' : '']),
            ]],
        ];
    }

    /** @return list<string> */
    private static function fields(DocumentType $type): array
    {
        return array_values(array_map(fn (FieldDefinition $f) => $f->name, array_filter($type->fields(), fn (FieldDefinition $f) => in_array($f->type, ['number', 'money'], true))));
    }
}
