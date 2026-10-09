<?php

namespace App\Core\CustomFields;

use App\Core\Workflow\DocumentTypes\FieldDefinition;

/**
 * CF-03: custom fields in workflow and automation conditions (WF-04,
 * WF-05, AUTO-02). A document type whose records carry custom fields
 * (custom forms, Task 7; any module document) names its entity in
 * DocumentType::customFieldEntity() and adds these fields to fields() and
 * fieldValues() (DocumentType::customFields(), customValues()).
 *
 * Fields are named `cf_<key>`, labelled as the admin typed them, typed for
 * the condition evaluator (CustomFieldTypes::conditionType); file fields
 * are left out. Values come in FieldDefinition's shapes: a multi-select as
 * its values joined by ", " (`contains` reads one), a lookup as the id.
 * Conditions run as the system, so every active field is offered; who sees
 * the outcome is the workflow's own business (notification texts are
 * filled with the field rules of their reader, AUTO-03).
 */
class CustomFieldDocumentFields
{
    public const PREFIX = 'cf_';

    public function __construct(private readonly CustomFieldDefinitions $definitions) {}

    /** @return list<FieldDefinition> */
    public function fields(string $entity): array
    {
        $fields = [];

        foreach ($this->definitions->active($entity) as $field) {
            $type = CustomFieldTypes::conditionType($field->type, $field->formula_type);

            if ($type === null) {
                continue;
            }

            $fields[] = new FieldDefinition(
                self::PREFIX.$field->key,
                $type,
                $field->label,
                $type === 'enum' ? $field->optionValues() : [],
                $type === 'reference' ? 'core.'.$field->lookup_target : null,
                literal: true,
            );
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>|null  $custom  the record's stored values
     * @return array<string, mixed> `cf_<key>` => value
     */
    public function values(string $entity, ?array $custom): array
    {
        $values = [];

        foreach ($this->definitions->active($entity) as $field) {
            if (CustomFieldTypes::conditionType($field->type, $field->formula_type) === null) {
                continue;
            }

            $value = $custom[$field->key] ?? null;
            $values[self::PREFIX.$field->key] = $field->type === 'multi_select' && is_array($value) ? implode(', ', $value) : $value;
        }

        return $values;
    }
}
