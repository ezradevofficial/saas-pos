<?php

namespace App\Core\Identity\Pin\Http\Requests;

use App\Core\Identity\Pin\Pins;
use App\Core\Sync\Http\Requests\DeviceRequest;
use Illuminate\Validation\Rule;

/**
 * AUTH-06, AUTH-07: POST pos/pin/verify {user_id, pin | card}, a staff
 * sign-in checked online. The user is looked up under row-level security.
 */
class VerifyPinRequest extends DeviceRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'pin' => ['required_without:card', 'prohibits:card', 'string', 'max:6'],
            'card' => ['required_without:pin', 'string', 'max:64'],
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
