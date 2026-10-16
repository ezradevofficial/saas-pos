<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST ecommerce/deliveries/{delivery}/assign (ECOM-27). */
class AssignRiderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return ['rider_id' => ['required', 'uuid', Rule::exists('ecom_riders', 'id')]];
    }
}
