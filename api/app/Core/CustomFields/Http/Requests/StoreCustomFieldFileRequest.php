<?php

namespace App\Core\CustomFields\Http\Requests;

use App\Core\CustomFields\CustomFieldAccess;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\CustomFieldFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CF-01: POST custom-field-files (multipart `entity`, `field`, `file`): a
 * file for a file field, before the record is saved. The user may create
 * or change the entity's records somewhere and may change the field
 * (RBAC-05). PDF, image, text, CSV, Word or Excel, at most 10 MB.
 */
class StoreCustomFieldFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = is_string($this->input('entity')) ? app(CustomFieldEntities::class)->find($this->input('entity')) : null;

        return $entity === null || $entity->writeAny($this->user());
    }

    public function rules(): array
    {
        return [
            'entity' => ['required', 'string', Rule::in(app(CustomFieldEntities::class)->keys())],
            'field' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file', 'max:'.CustomFieldFiles::MAX_KB, 'mimetypes:'.implode(',', array_keys(CustomFieldFiles::MIMES))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $field = $this->definition();
            $access = app(CustomFieldAccess::class)->for($this->user(), (string) $this->input('entity'));

            if ($field === null || $field->type !== 'file' || in_array($field->key, [...$access['hidden'], ...$access['readonly']], true)) {
                $validator->errors()->add('field', __('core.custom_field.file_field_invalid'));
            }
        }];
    }

    public function definition(): ?CustomFieldDefinition
    {
        return app(CustomFieldDefinitions::class)->byKey((string) $this->input('entity'))[(string) $this->input('field')] ?? null;
    }

    public function attributes(): array
    {
        return ['file' => __('core.custom_field.attributes.file')];
    }
}
