<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST ecommerce/rider/updates (ECOM-28): status and location updates queued on the phone, idempotent by their UUID v7. */
class RiderUpdatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'updates' => ['required', 'array', 'min:1', 'max:'.(int) config('ecommerce.rider.max_updates', 100)],
            'updates.*.id' => ['required', 'uuid', 'distinct'],
            'updates.*.delivery_id' => ['required', 'uuid'],
            'updates.*.kind' => ['required', 'string', Rule::in(['status', 'location'])],
            'updates.*.status' => ['required_if:updates.*.kind,status', 'nullable', 'string', Rule::in(['picked_up', 'delivered', 'failed'])],
            'updates.*.latitude' => ['required_if:updates.*.kind,location', 'nullable', 'numeric', 'between:-90,90'],
            'updates.*.longitude' => ['required_if:updates.*.kind,location', 'nullable', 'numeric', 'between:-180,180'],
            'updates.*.occurred_at' => ['required', 'date'],
            'updates.*.code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{6}$/'],
            'updates.*.collected_minor' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}$/'],
            'updates.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
