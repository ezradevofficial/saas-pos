<?php

namespace App\Core\CustomFields\Http\Requests;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\CustomFieldError;
use App\Core\CustomFields\CustomFieldTypes;
use App\Core\CustomFields\CustomFieldValues;
use App\Core\CustomFields\Formula\Formula;
use App\Core\CustomFields\Formula\FormulaError;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CF-01, CF-02: validation of a custom field definition, shared by create
 * and update. The entity, key and type are set once (422 `immutable` when
 * an update names other ones). Settings must suit the type: options for
 * dropdowns, a target for lookups, a formula for formula fields (parsed by
 * Formula, reading only the entity's other active, non-formula fields),
 * min/max for numbers and text, a pattern for text, unique only for scalar
 * types. The default must be a valid value of the field. Role ids are the
 * tenant's active roles (row-level security).
 */
final class CustomFieldRules
{
    public const MAX_OPTION = 100;

    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $role = Rule::exists('roles', 'id')->whereNull('archived_at');

        return [
            'entity' => [...$required, 'string', Rule::in(app(CustomFieldEntities::class)->keys())],
            'key' => [...$required, 'string', 'max:40', 'regex:'.CustomFieldTypes::KEY_PATTERN],
            'type' => [...$required, 'string', Rule::in(CustomFieldTypes::ALL)],
            // CF-02: one label, as the admin types it.
            'label' => [...$required, 'string', 'max:100'],
            'help' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default' => ['sometimes', 'nullable'],
            'required' => ['sometimes', 'boolean'],
            'unique' => ['sometimes', 'boolean'],
            'min' => ['sometimes', 'nullable', 'string', 'regex:'.CustomFieldTypes::NUMBER_PATTERN],
            'max' => ['sometimes', 'nullable', 'string', 'regex:'.CustomFieldTypes::NUMBER_PATTERN],
            'pattern' => ['sometimes', 'nullable', 'string', 'max:255'],
            'options' => ['sometimes', 'array', 'max:'.CustomFieldTypes::MAX_OPTIONS],
            'options.*' => ['array:value,label'],
            'options.*.value' => ['required', 'string', 'max:'.self::MAX_OPTION, 'distinct'],
            'options.*.label' => ['required', 'string', 'max:'.self::MAX_OPTION],
            'lookup_target' => ['sometimes', 'nullable', 'string', 'max:40'],
            'formula' => ['sometimes', 'nullable', 'string', 'max:'.Formula::MAX_LENGTH],
            'formula_type' => ['sometimes', 'nullable', 'string', Rule::in(CustomFieldTypes::FORMULA_TYPES)],
            'visible_roles' => ['sometimes', 'array', 'max:100'],
            'visible_roles.*' => ['uuid', 'distinct', $role],
            'editable_roles' => ['sometimes', 'array', 'max:100'],
            'editable_roles.*' => ['uuid', 'distinct', $role],
            'show_on_pos' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'between:0,10000'],
        ];
    }

    /** Cross-field checks against the definition as it will be saved. */
    public static function validate(Validator $validator, array $input, ?CustomFieldDefinition $existing): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($existing !== null) {
            foreach (['entity', 'key', 'type'] as $fixed) {
                if (array_key_exists($fixed, $input) && $input[$fixed] !== $existing->{$fixed}) {
                    $validator->errors()->add($fixed, __('core.custom_field.immutable'));
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }
        }

        $field = self::draft($input, $existing);
        $type = $field->type;
        $errors = $validator->errors();

        if ($existing === null && CustomFieldDefinition::query()->where('entity', $field->entity)->where('key', $field->key)->exists()) {
            $errors->add('key', __('core.custom_field.key_taken'));
        }

        if ($field->is_unique && ! in_array($type, CustomFieldTypes::UNIQUE, true)) {
            $errors->add('unique', __('core.custom_field.setting_not_for_type'));
        }

        if ($type === 'formula' && $field->required) {
            $errors->add('required', __('core.custom_field.setting_not_for_type'));
        }

        foreach (['min' => 'min_value', 'max' => 'max_value'] as $name => $column) {
            if ($field->{$column} !== null && ! in_array($type, CustomFieldTypes::RANGED, true)) {
                $errors->add($name, __('core.custom_field.setting_not_for_type'));
            }
        }

        if ($field->min_value !== null && $field->max_value !== null && BigDecimal::of($field->min_value)->isGreaterThan($field->max_value)) {
            $errors->add('max', __('core.custom_field.max_below_min'));
        }

        if ($type !== 'number' && ($field->min_value !== null && ! self::isCount($field->min_value) || $field->max_value !== null && ! self::isCount($field->max_value))) {
            $errors->add('min', __('core.custom_field.length_whole'));
        }

        if ($field->pattern !== null && $field->pattern !== '') {
            if (! in_array($type, CustomFieldTypes::PATTERNED, true)) {
                $errors->add('pattern', __('core.custom_field.setting_not_for_type'));
            } elseif (CustomFieldValues::matches($field->pattern, '') === null) {
                $errors->add('pattern', __('core.custom_field.pattern_invalid'));
            }
        }

        if (in_array($type, CustomFieldTypes::OPTIONS, true)) {
            if (($field->options ?? []) === []) {
                $errors->add('options', __('core.custom_field.options_required'));
            }
        } elseif (($field->options ?? []) !== []) {
            $errors->add('options', __('core.custom_field.setting_not_for_type'));
        }

        if ($type === 'lookup') {
            if ($field->lookup_target === null || app(CustomFieldEntities::class)->lookup($field->lookup_target) === null) {
                $errors->add('lookup_target', __('core.custom_field.lookup_target_invalid'));
            }
        } elseif ($field->lookup_target !== null) {
            $errors->add('lookup_target', __('core.custom_field.setting_not_for_type'));
        }

        if ($type === 'formula') {
            self::validateFormula($validator, $field);
        } elseif ($field->formula !== null || $field->formula_type !== null) {
            $errors->add('formula', __('core.custom_field.setting_not_for_type'));
        }

        if ($field->default_value !== null) {
            if (in_array($type, ['file', 'lookup', 'formula'], true)) {
                $errors->add('default', __('core.custom_field.setting_not_for_type'));
            } elseif ($errors->isEmpty()) {
                $default = app(CustomFieldValues::class)->normalise($field, $field->default_value);

                if ($default instanceof CustomFieldError) {
                    $errors->add('default', $default->message($field));
                }
            }
        }
    }

    /**
     * Model attributes from validated input (only the fields given).
     *
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $map = [
            'entity' => 'entity', 'key' => 'key', 'type' => 'type', 'label' => 'label', 'help' => 'help',
            'default' => 'default_value', 'required' => 'required', 'unique' => 'is_unique', 'min' => 'min_value',
            'max' => 'max_value', 'pattern' => 'pattern', 'options' => 'options', 'lookup_target' => 'lookup_target',
            'formula' => 'formula', 'formula_type' => 'formula_type', 'visible_roles' => 'visible_roles',
            'editable_roles' => 'editable_roles', 'show_on_pos' => 'show_on_pos', 'position' => 'position',
        ];
        $attributes = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        foreach (['label', 'help', 'pattern', 'formula'] as $text) {
            if (isset($attributes[$text]) && is_string($attributes[$text])) {
                $attributes[$text] = trim($attributes[$text]) === '' && $text !== 'label' ? null : trim($attributes[$text]);
            }
        }

        if (isset($attributes['options'])) {
            $attributes['options'] = array_values(array_map(fn (array $o) => ['value' => trim((string) $o['value']), 'label' => trim((string) $o['label'])], $attributes['options']));
        }

        foreach (['visible_roles', 'editable_roles'] as $roles) {
            if (array_key_exists($roles, $attributes)) {
                $attributes[$roles] = array_values(array_unique(array_map('strtolower', $attributes[$roles] ?? [])));
            }
        }

        if (($attributes['type'] ?? null) === 'formula' && ! isset($attributes['formula_type'])) {
            $attributes['formula_type'] = 'number';
        }

        return $attributes;
    }

    /** @return array<string, string> */
    public static function attributeNames(): array
    {
        return collect([
            'entity' => 'entity', 'key' => 'key', 'type' => 'type', 'label' => 'label', 'help' => 'help',
            'default' => 'default', 'required' => 'required', 'unique' => 'unique', 'min' => 'min', 'max' => 'max',
            'pattern' => 'pattern', 'options' => 'options', 'options.*.value' => 'option_value', 'options.*.label' => 'option_label',
            'lookup_target' => 'lookup_target', 'formula' => 'formula', 'formula_type' => 'formula_type',
            'visible_roles' => 'visible_roles', 'editable_roles' => 'editable_roles', 'show_on_pos' => 'show_on_pos', 'position' => 'position',
        ])->map(fn (string $key) => __("core.custom_field.attributes.{$key}"))->all();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'key.regex' => __('core.custom_field.key_invalid'),
            'min.regex' => __('core.custom_field.bound_invalid'),
            'max.regex' => __('core.custom_field.bound_invalid'),
        ];
    }

    private static function validateFormula(Validator $validator, CustomFieldDefinition $field): void
    {
        if ($field->formula === null) {
            $validator->errors()->add('formula', __('core.custom_field.formula_required'));

            return;
        }

        $known = CustomFieldDefinition::query()->where('entity', $field->entity)->whereNull('archived_at')
            ->where('type', '!=', 'formula')->where('key', '!=', $field->key)->pluck('key')->all();

        try {
            Formula::parse($field->formula, $known);
        } catch (FormulaError $e) {
            $validator->errors()->add('formula', $e->translated());
        }
    }

    /** The definition as it would be saved (not persisted). */
    private static function draft(array $input, ?CustomFieldDefinition $existing): CustomFieldDefinition
    {
        $field = $existing?->replicate() ?? new CustomFieldDefinition;
        $field->forceFill(self::attributes($input));

        return $field;
    }

    private static function isCount(string $value): bool
    {
        return preg_match('/^\d{1,6}(\.0+)?\z/', $value) === 1;
    }
}
