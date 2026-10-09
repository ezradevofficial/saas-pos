<?php

namespace App\Core\Identity\Pin\Http\Requests;

use App\Core\Identity\Pin\Pins;
use App\Core\Sync\Http\Requests\DeviceRequest;
use Illuminate\Validation\Rule;

/**
 * AUTH-06, AUTH-07: POST pos/pin/verify {user_id, pin | card, session_id?,
 * signed_in_at?}, a staff sign-in checked online. The user is looked up
 * under row-level security. `session_id` (a UUID the device made for this
 * sign-in) records the sign-in, so the device's attestation for it
 * verifies as online; `signed_in_at` is the time the device signs in that
 * attestation (ISO 8601 with `Z` or an offset), kept as sent.
 */
class VerifyPinRequest extends DeviceRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'pin' => ['required_without:card', 'prohibits:card', 'string', 'max:6'],
            'card' => ['required_without:pin', 'string', 'max:64'],
            'session_id' => ['nullable', 'uuid'],
            'signed_in_at' => ['nullable', 'string', 'max:40', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}(:?\d{2})?)$/', 'date'],
        ];
    }

    public function kind(): string
    {
        return $this->filled('card') ? Pins::CARD : Pins::PIN;
    }

    public function secret(): string
    {
        return (string) $this->validated($this->kind());
    }
}
