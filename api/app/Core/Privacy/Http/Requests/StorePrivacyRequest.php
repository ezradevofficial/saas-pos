<?php

namespace App\Core\Privacy\Http\Requests;

use App\Core\Privacy\Models\PrivacyRequest;
use App\Core\Privacy\PrivacySubjects;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * NFR-08: log a data subject request. The subject must exist in this
 * tenant (checked under row-level security by PrivacyRequests). A
 * correction names only the correctable fields of the subject type, each
 * validated by the rules its module declared.
 */
class StorePrivacyRequest extends PrivacyRequestAction
{
    protected string $permission = 'core.privacy_request.manage';

    public function rules(): array
    {
        $subjects = app(PrivacySubjects::class);
        $type = (string) $this->input('subject_type');
        $rules = [
            'subject_type' => ['required', 'string', Rule::in($subjects->types())],
            'subject_id' => ['required', 'uuid'],
            'kind' => ['required', 'string', Rule::in(PrivacyRequest::KINDS)],
            'note' => ['nullable', 'string', 'max:2000'],
            'corrections' => [Rule::requiredIf($this->input('kind') === PrivacyRequest::CORRECTION), 'array', 'min:1'],
        ];

        if ($this->input('kind') === PrivacyRequest::CORRECTION && in_array($type, $subjects->types(), true)) {
            foreach ($subjects->primary($type)->correctable as $field => $fieldRules) {
                $rules["corrections.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $type = (string) $this->input('subject_type');
            $subjects = app(PrivacySubjects::class);
            if ($this->input('kind') !== PrivacyRequest::CORRECTION || ! in_array($type, $subjects->types(), true) || ! is_array($this->input('corrections'))) {
                return;
            }

            $unknown = array_diff(array_keys($this->input('corrections')), $subjects->primary($type)->correctableFields());
            foreach ($unknown as $field) {
                $validator->errors()->add("corrections.{$field}", __('privacy.errors.not_correctable'));
            }
        }];
    }
}
