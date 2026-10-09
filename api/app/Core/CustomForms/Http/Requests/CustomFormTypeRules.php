<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomFormType;
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

    /** @return array<string, string> */
    public static function attributeNames(): array
    {
        return collect(['key', 'name', 'description', 'workflow', 'has_lines', 'attachments', 'line_fields', 'role_ids'])
            ->mapWithKeys(fn (string $key) => [$key => __("core.custom_form_type.attributes.{$key}")])->all();
    }
}
