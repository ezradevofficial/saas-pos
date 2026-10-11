<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomFormsServiceProvider;
use App\Core\CustomForms\CustomFormType;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CF-04: validation of a custom form type, shared by create and update.
 * The key is set once (never reused, even once archived). The name is
 * typed once. Line fields name the type's line custom fields; role ids
 * are the tenant's active roles (row-level security). The workflow can't
 * be turned off while records wait in a flow.
 */
final class CustomFormTypeRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'key' => [...($updating ? ['prohibited'] : ['required']), 'string', 'max:30', 'regex:'.CustomFormType::KEY_PATTERN],
            'name' => [...$required, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'workflow' => ['sometimes', 'boolean'],
            'has_lines' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'boolean'],
            'line_fields' => ['sometimes', 'array', 'max:50'],
            'line_fields.*' => ['string', 'distinct', 'max:40'],
            'role_ids' => ['sometimes', 'array', 'max:100'],
            'role_ids.*' => ['uuid', 'distinct', Rule::exists('roles', 'id')->whereNull('archived_at')],
        ];
    }

    public static function validate(Validator $validator, array $input, ?CustomFormType $existing): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($existing === null && CustomFormType::query()->where('key', $input['key'])->exists()) {
            $validator->errors()->add('key', __('core.custom_form_type.key_taken'));
        } elseif ($existing === null && self::prefixInUse((string) $input['key'])) {
            $validator->errors()->add('key', __('core.custom_form_type.prefix_taken', ['prefix' => self::literalPrefix(CustomFormsServiceProvider::defaultPattern((string) $input['key']))]));
        }

        $key = $existing?->key ?? (string) $input['key'];

        foreach ($input['line_fields'] ?? [] as $i => $field) {
            if (! CustomFieldDefinition::query()->where('entity', CustomFormType::LINE_ENTITY_PREFIX.$key)->where('key', $field)->exists()) {
                $validator->errors()->add("line_fields.{$i}", __('core.custom_form_type.line_field_unknown', ['field' => $field]));
            }
        }

        if ($existing !== null && $existing->workflow && ($input['workflow'] ?? true) === false
            && CustomFormRecord::query()->where('type_id', $existing->id)->where('status', CustomFormRecord::PENDING)->exists()) {
            $validator->errors()->add('workflow', __('core.custom_form_type.workflow_in_use'));
        }
    }

    /**
     * NUM-01: whether the new type's default number prefix (`KEY-`) is the
     * literal prefix of a number format the tenant has, or of another
     * numbered type's default. NumberPrefixes checks collisions within one
     * document type only, so a new form type must not start printing
     * another type's numbers.
     */
    private static function prefixInUse(string $key): bool
    {
        $prefix = self::literalPrefix(CustomFormsServiceProvider::defaultPattern($key));
        $patterns = [
            ...NumberFormat::query()->pluck('pattern')->all(),
            ...array_map(fn (DocumentNumberType $type) => $type->defaultPattern, app(DocumentNumberTypes::class)->active()),
        ];

        foreach ($patterns as $pattern) {
            if (strcasecmp(self::literalPrefix((string) $pattern), $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /** The text of a pattern before its first token (`PETTY_CASH-` of `PETTY_CASH-{YYYY}-{00001}`). */
    private static function literalPrefix(string $pattern): string
    {
        return strstr($pattern, '{', true) ?: $pattern;
    }

    /** @return array<string, string> */
    public static function attributeNames(): array
    {
        return collect(['key', 'name', 'description', 'workflow', 'has_lines', 'attachments', 'line_fields', 'role_ids'])
            ->mapWithKeys(fn (string $key) => [$key => __("core.custom_form_type.attributes.{$key}")])->all();
    }
}
