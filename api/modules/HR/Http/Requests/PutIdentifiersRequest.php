<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Support\Sensitive;

/**
 * PUT hr/employees/{hr_employee}/identifiers (HR-03): `hr.identity.edit`
 * at the place, `identifiers` neither hidden nor locked by field rules.
 * `{identifiers: [{type, value}]}`; an empty value clears that type.
 */
class PutIdentifiersRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.identity.edit') && $this->sensitive()->mayEdit($this->user(), $this->employee(), Sensitive::IDENTITY);
    }

    public function rules(): array
    {
        return [
            'identifiers' => ['required', 'array', 'min:1', 'max:10'],
            'identifiers.*.type' => ['required', 'string', 'max:40', 'distinct'],
            'identifiers.*.value' => ['present', 'nullable', 'string', 'max:64'],
        ];
    }
}
