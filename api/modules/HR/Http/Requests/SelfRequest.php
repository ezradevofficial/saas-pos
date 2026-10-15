<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Employee;
use Modules\HR\Support\Errors;

/**
 * HR-31: employee self-service. Being the signed-in user linked to an
 * active employee is the authorisation (AUTH-12); anyone else gets 404
 * `not_an_employee`. Subclasses add their body.
 */
class SelfRequest extends HrRequest
{
    private ?Employee $me = null;

    public function me(): Employee
    {
        return $this->me ??= Employee::query()->where('user_id', $this->user()->id)->where('status', Employee::ACTIVE)->first()
            ?? throw Errors::make(404, 'not_an_employee');
    }

    public function authorize(): bool
    {
        $this->me();

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
