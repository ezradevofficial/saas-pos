<?php

namespace Modules\HR\Http\Requests;

/** PATCH hr/me (HR-31): the employee's personal contacts, address and emergency contacts. */
class UpdateSelfRequest extends SelfRequest
{
    public const FIELDS = ['personal_email', 'personal_phone', 'address', 'emergency_contacts'];

    public function rules(): array
    {
        return array_filter(EmployeeRules::rules(false), fn (string $key) => in_array(strtok($key, '.'), self::FIELDS, true), ARRAY_FILTER_USE_KEY);
    }
}
