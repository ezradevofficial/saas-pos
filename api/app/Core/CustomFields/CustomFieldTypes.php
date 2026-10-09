<?php

namespace App\Core\CustomFields;

/**
 * CF-01: the custom field types, and what each supports (CF-02 settings,
 * filters, workflow conditions). Stored value shapes in `custom` jsonb:
 *
 *   text, long_text, select  a string (select: one of the option values)
 *   number                   a decimal string ("12.5"), never a float
 *   money                    {"amount_minor": "1250", "currency": "KES"} (ADR 003)
 *   date                     "Y-m-d"
 *   datetime                 ISO 8601 in UTC, "Y-m-d\TH:i:s\Z"
 *   boolean                  true or false
 *   multi_select             a list of option values
 *   file                     a custom_field_files id
 *   lookup                   the id of the target record
 *   formula                  its computed value (number: decimal string; text; boolean)
 */
final class CustomFieldTypes
{
    public const ALL = ['text', 'long_text', 'number', 'money', 'date', 'datetime', 'boolean', 'select', 'multi_select', 'file', 'lookup', 'formula'];

    /** May be unique (one record per value, per tenant and entity). */
    public const UNIQUE = ['text', 'number', 'date', 'select', 'lookup'];

    /** min/max: a value range for numbers, a length range for text. */
    public const RANGED = ['text', 'long_text', 'number'];

    public const PATTERNED = ['text'];

    public const OPTIONS = ['select', 'multi_select'];

    /** Listed as columns and sortable. */
    public const SORTABLE = ['text', 'number', 'money', 'date', 'datetime', 'boolean', 'select', 'formula'];

    /** Filtered by `?custom[key]=value`. */
    public const FILTERABLE = ['text', 'long_text', 'number', 'date', 'datetime', 'boolean', 'select', 'multi_select', 'lookup', 'formula'];

    /** Filtered by `?custom[key][min]=` / `[max]=`. */
    public const RANGE_FILTERS = ['number', 'date', 'datetime'];

    public const FORMULA_TYPES = ['number', 'text', 'boolean'];

    public const MAX_OPTIONS = 100;

    public const MAX_TEXT = 255;

    public const MAX_LONG_TEXT = 5000;

    /** Number values: up to 24 integer digits and 6 decimals (numeric(30,6)). */
    public const NUMBER_PATTERN = '/^-?\d{1,24}(\.\d{1,6})?\z/';

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}\z/';

    /** The workflow field type (FieldDefinition) of a custom field, or null when conditions can't use it. */
    public static function conditionType(string $type, ?string $formulaType = null): ?string
    {
        return match ($type) {
            'text', 'long_text', 'multi_select' => 'string',
            'number' => 'number',
            'money' => 'money',
            'date', 'datetime' => 'date',
            'boolean' => 'boolean',
            'select' => 'enum',
            'lookup' => 'reference',
            'formula' => match ($formulaType) {
                'text' => 'string',
                'boolean' => 'boolean',
                default => 'number',
            },
            default => null,
        };
    }
}
