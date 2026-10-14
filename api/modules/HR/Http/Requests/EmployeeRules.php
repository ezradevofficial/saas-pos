<?php

namespace Modules\HR\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use Illuminate\Validation\Rule;
use Modules\HR\Models\Employee;

/** HR-02: the employee fields' rules, required on create and `sometimes` on update. */
final class EmployeeRules
{
    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'number' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/_.]*$/'],
            'first_name' => [$required, 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', Rule::in(Employee::GENDERS)],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'personal_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'personal_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address.line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'emergency_contacts' => ['sometimes', 'array', 'max:5'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:150'],
            'emergency_contacts.*.relationship' => ['sometimes', 'nullable', 'string', 'max:60'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:32'],
            'emergency_contacts.*.alt_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'emergency_contacts.*.email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'medical_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hire_date' => [$required, 'date_format:Y-m-d'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id')],
            'location_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('locations', 'id')],
            'department_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('departments', 'id')],
            'position_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('hr_positions', 'id')],
            'reports_to_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('hr_employees', 'id')],
            'user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
            'clock_methods' => ['sometimes', 'array'],
            'clock_methods.*' => ['string', Rule::in(Employee::CLOCK_METHODS)],
            'biometric_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
            ...CustomFieldValidator::rules(),
        ];
    }

    /** HR-09: the sensitive input keys and the group whose edit permission writes them. */
    public const SENSITIVE_INPUTS = ['date_of_birth' => 'identity', 'medical_notes' => 'medical'];
}
