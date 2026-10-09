<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * CF-04: PATCH custom-form-records/{record}: change a draft (its creator
 * holding `core.custom_form.create`, or `core.custom_form.edit`, at its
 * place). Its place never changes.
 */
class UpdateCustomFormRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->edit($this->user(), $this->record());
    }

    public function record(): CustomFormRecord
    {
        return $this->route('custom_form_record');
    }

    public function rules(): array
    {
        return CustomFormRecordRules::rules($this->record()->type);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                CustomFormRecordRules::validate($validator, $this->record()->type, $this->record(), $this->user());
            }
        }];
    }

    /** @return array{custom: ?array, lines: ?array, attachments: ?array, submit: bool} */
    public function recordData(): array
    {
        return [
            'custom' => $this->validated('custom'),
            'lines' => $this->validated('lines'),
            'attachments' => $this->validated('attachments'),
            'submit' => $this->boolean('submit'),
        ];
    }
}
