<?php

namespace App\Core\CustomFields;

use App\Core\Identity\Models\User;

/**
 * CF-03: custom fields as merge fields of document templates (TPL, Task 4)
 * and of any text filled from a record. Named `custom.<key>`, labelled as
 * typed; the values are display text (CustomFieldPresenter::text): lookups
 * by label, files by name, options by label, money with its currency code
 * first. Only fields the reader may see are offered or filled (RBAC-05);
 * pass null for a system render (printed receipts), which sees every
 * active field.
 *
 *   $merge->fields('item', $user)            [['name' => 'custom.size', 'label' => 'Size', 'type' => 'text'], ...]
 *   $merge->values('item', $user, $custom)   ['custom.size' => 'Large', ...]
 */
class CustomFieldMergeFields
{
    public const PREFIX = 'custom.';

    public function __construct(
        private readonly CustomFieldDefinitions $definitions,
        private readonly CustomFieldAccess $access,
        private readonly CustomFieldPresenter $presenter,
    ) {}

    /** @return list<array{name: string, label: string, type: string}> */
    public function fields(string $entity, ?User $reader): array
    {
        $hidden = $this->access->for($reader, $entity)['hidden'];

        return $this->definitions->active($entity)
            ->reject(fn (CustomFieldDefinition $field) => in_array($field->key, $hidden, true))
            ->map(fn (CustomFieldDefinition $field) => ['name' => self::PREFIX.$field->key, 'label' => $field->label, 'type' => $field->type])
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $custom  the record's stored values
     * @return array<string, string> `custom.<key>` => text (every offered field, '' when empty)
     */
    public function values(string $entity, ?User $reader, ?array $custom): array
    {
        $texts = $this->presenter->text($reader, $entity, $custom);
        $values = [];

        foreach ($this->fields($entity, $reader) as $field) {
            $values[$field['name']] = $texts[substr($field['name'], strlen(self::PREFIX))] ?? '';
        }

        return $values;
    }
}
