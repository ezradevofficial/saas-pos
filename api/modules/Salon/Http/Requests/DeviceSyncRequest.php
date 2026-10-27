<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST salon/device/sync (SAL-29): a paired till's token (the route's middleware); the place is the device's, never the body's. At most 100 records in all. */
class DeviceSyncRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'appointments' => ['sometimes', 'array', 'max:100'],
            'appointments.*.id' => ['required', 'uuid'],
            'appointments.*.user_id' => ['required', 'uuid'],
            'appointments.*.actor_proof' => ['nullable', 'array'],
            'appointments.*.client_id' => ['sometimes', 'nullable', 'uuid'],
            'appointments.*.guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'appointments.*.guest_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'appointments.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'appointments.*.source' => ['sometimes', 'string', 'in:device,walk_in'],
            'appointments.*.starts_at' => ['required', 'date'],
            'appointments.*.override_reason' => ['sometimes', 'nullable', 'string', 'max:200'],
            'appointments.*.lines' => ['required', 'array', 'min:1', 'max:12'],
            'appointments.*.lines.*.service_id' => ['required', 'uuid'],
            'appointments.*.lines.*.staff_id' => ['sometimes', 'nullable', 'uuid'],
            'appointments.*.lines.*.resource_id' => ['sometimes', 'nullable', 'uuid'],

            'events' => ['sometimes', 'array', 'max:100'],
            'events.*.id' => ['required', 'uuid'],
            'events.*.appointment_id' => ['required', 'uuid'],
            'events.*.user_id' => ['required', 'uuid'],
            'events.*.actor_proof' => ['nullable', 'array'],
            'events.*.type' => ['required', Rule::in(['check_in', 'start', 'finish', 'no_show', 'cancel'])],
            'events.*.at' => ['sometimes', 'nullable', 'date'],
            'events.*.reason' => ['sometimes', 'nullable', 'string', 'max:200'],

            'queue' => ['sometimes', 'array', 'max:100'],
            'queue.*.id' => ['required', 'uuid'],
            'queue.*.user_id' => ['required', 'uuid'],
            'queue.*.actor_proof' => ['nullable', 'array'],
            'queue.*.action' => ['required', Rule::in(['join', 'seat', 'leave'])],
            'queue.*.guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'queue.*.guest_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'queue.*.client_id' => ['sometimes', 'nullable', 'uuid'],
            'queue.*.service_ids' => ['sometimes', 'array', 'max:12'],
            'queue.*.service_ids.*' => ['uuid'],
            'queue.*.staff_id' => ['sometimes', 'nullable', 'uuid'],
            'queue.*.resource_id' => ['sometimes', 'nullable', 'uuid'],
            'queue.*.entry_id' => ['required_unless:queue.*.action,join', 'nullable', 'uuid'],
            'queue.*.appointment_id' => ['sometimes', 'nullable', 'uuid'],
            'queue.*.at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $total = collect(['appointments', 'events', 'queue'])->sum(fn (string $key) => count((array) $this->input($key, [])));

            if ($total === 0 || $total > 100) {
                $validator->errors()->add('appointments', __('salon.errors.batch_size'));
            }
        }];
    }
}
