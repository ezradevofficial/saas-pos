<?php

namespace App\Core\CustomFields;

use App\Core\Audit\Audited;
use App\Core\CustomFields\Formula\Formula;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CF-01, CF-02: one custom field of a registered entity (CustomFieldEntities).
 * The entity, key and type never change once created; the key is never
 * reused, even after archiving, so stored values keep their meaning.
 * Archived, never deleted (TEN-06): its values stay on the records and come
 * back with a restore. Every change is audited as `core.custom_field.*`.
 *
 * @property string $entity
 * @property string $key
 * @property string $type
 * @property string $label
 * @property list<array{value: string, label: string}> $options
 * @property list<string> $visible_roles
 * @property list<string> $editable_roles
 */
class CustomFieldDefinition extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditResource = 'custom_field';

    protected $fillable = [
        'entity', 'key', 'type', 'label', 'help', 'default_value', 'required', 'is_unique', 'min_value', 'max_value',
        'pattern', 'options', 'lookup_target', 'formula', 'formula_type', 'visible_roles', 'editable_roles', 'show_on_pos', 'position',
    ];

    protected $attributes = [
        'options' => '[]',
        'visible_roles' => '[]',
        'editable_roles' => '[]',
        'required' => false,
        'is_unique' => false,
        'show_on_pos' => false,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'default_value' => 'json',
            'options' => 'array',
            'visible_roles' => 'array',
            'editable_roles' => 'array',
            'required' => 'boolean',
            'is_unique' => 'boolean',
            'show_on_pos' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** min/max as stored, without trailing zeros ("10", "2.5"), or null. */
    public function bound(string $which): ?string
    {
        $value = $this->{$which === 'min' ? 'min_value' : 'max_value'};

        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        // Only a decimal part loses trailing zeros ("100.000000" -> "100"); "100" stays "100".
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /** @return list<string> the option values */
    public function optionValues(): array
    {
        return array_values(array_map(fn (array $option) => (string) $option['value'], $this->options ?? []));
    }

    public function parsedFormula(): ?Formula
    {
        return $this->formula === null ? null : Formula::parse($this->formula);
    }
}
