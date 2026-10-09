<?php

namespace App\Core\Automation\Templates;

use App\Core\Automation\Capabilities\Capabilities;
use App\Core\Automation\Capabilities\FindsDocumentsByDate;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * AUTO-07, generic: "Remind the owner N days before a date" (contract
 * expiry, due dates) for any type with a date field it can search by
 * (FindsDocumentsByDate). Notifies the person in the type's first user
 * field, else the Admin role.
 */
class RemindBeforeDate implements RuleTemplate
{
    public function key(): string
    {
        return 'core.remind_before_date';
    }

    public function label(): string
    {
        return 'automation.templates.remind_before_date.label';
    }

    public function description(): string
    {
        return 'automation.templates.remind_before_date.description';
    }

    public function appliesTo(DocumentType $type): bool
    {
        return $type instanceof FindsDocumentsByDate && Capabilities::dateFields($type) !== [];
    }

    public function parameters(DocumentType $type): array
    {
        return [
            ['name' => 'field', 'kind' => 'field', 'fields' => Capabilities::dateFields($type), 'default' => Capabilities::dateFields($type)[0] ?? null],
            ['name' => 'days', 'kind' => 'days', 'default' => 7],
            ['name' => 'to', 'kind' => 'recipients', 'default' => self::owner($type)],
        ];
    }

    public function build(DocumentType $type, array $params): array
    {
        $field = $params['field'] ?? Capabilities::dateFields($type)[0] ?? null;
        $days = $params['days'] ?? 7;
        $label = is_string($field) && $type->field($field) !== null ? $type->field($field)->displayLabel() : '';

        return [
            'name' => __('automation.templates.remind_before_date.name', ['field' => $label, 'days' => is_int($days) ? $days : 7]),
            'trigger' => ['type' => 'date', 'field' => $field, 'days' => $days, 'when' => 'before'],
            'conditions' => null,
            'actions' => [[
                'type' => 'notify',
                'to' => $params['to'] ?? self::owner($type),
                'subject' => __('automation.templates.remind_before_date.subject', ['field' => $label]),
                'message' => __('automation.templates.remind_before_date.message', ['field' => $label, 'date' => is_string($field) ? '{'.$field.'}' : '']),
            ]],
        ];
    }

    /** @return list<string> */
    private static function owner(DocumentType $type): array
    {
        $users = Capabilities::userFields($type);

        return $users === [] ? ['role:admin'] : ['field:'.$users[0]];
    }
}
