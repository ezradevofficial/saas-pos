<?php

namespace App\Core\Sync\Sources;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * CF-03, NFR-04: the custom fields shown on the POS (`show_on_pos`, active,
 * not files): how the till labels and shows the `custom` values that items
 * and customers carry (ItemSource, CustomerSource). Labels as the admin
 * typed them. Which cashier sees which field travels with the staff rows'
 * field rules (`custom.<key>`, StaffDirectory).
 */
class CustomFieldSource implements SnapshotSource
{
    /** The entities the till receives custom values for, by sync entity. */
    public const ENTITIES = ['item' => 'items', 'party' => 'customers'];

    public function key(): string
    {
        return 'custom_fields';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $rows = [];

        foreach (self::ENTITIES as $entity => $syncEntity) {
            foreach (self::posFields($entity) as $field) {
                $rows[] = [
                    'id' => $field->id,
                    'entity' => $syncEntity,
                    'key' => $field->key,
                    'type' => $field->type,
                    'label' => $field->label,
                    'options' => array_values($field->options ?? []),
                    'formula_type' => $field->formula_type,
                    'position' => $field->position,
                ];
            }
        }

        return $rows;
    }

    /**
     * A record's values of the fields shown on the POS (stored shapes,
     * CustomFieldTypes; files left out), for ItemSource and CustomerSource.
     *
     * @param  list<CustomFieldDefinition>  $fields  from posFields()
     * @return array<string, mixed>
     */
    public static function values(array $fields, ?string $custom): array
    {
        $stored = json_decode((string) $custom, true) ?: [];
        $values = [];

        foreach ($fields as $field) {
            if (array_key_exists($field->key, $stored) && $stored[$field->key] !== null) {
                $values[$field->key] = $stored[$field->key];
            }
        }

        return $values;
    }

    /** @return list<CustomFieldDefinition> the entity's active, non-file fields shown on the POS */
    public static function posFields(string $entity): array
    {
        // Read once per request with the entity's other fields (CustomFieldDefinitions).
        return app(CustomFieldDefinitions::class)->active($entity)
            ->filter(fn (CustomFieldDefinition $field) => $field->show_on_pos && $field->type !== 'file')
            ->values()->all();
    }
}
