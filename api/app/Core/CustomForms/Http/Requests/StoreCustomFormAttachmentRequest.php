<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomFields\CustomFieldFiles;
use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CF-04: POST custom-form-types/{type}/attachments (multipart `file`): a
 * file for a record of a type that takes attachments, before the record
 * is saved, by someone who may create or edit its records. PDF, image,
 * text, CSV, Word or Excel, at most 10 MB.
 */
class StoreCustomFormAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->type();

        return $type->attachments && ! $type->isArchived()
            && app(CustomFormAccess::class)->anywhere($this->user(), $type, [CustomFormAccess::CREATE, CustomFormAccess::EDIT]);
    }

    public function type(): CustomFormType
    {
        return $this->route('custom_form_type');
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.CustomFieldFiles::MAX_KB, 'mimetypes:'.implode(',', array_keys(CustomFieldFiles::MIMES))]];
    }

    public function attributes(): array
    {
        return ['file' => __('core.custom_field.attributes.file')];
    }
}
