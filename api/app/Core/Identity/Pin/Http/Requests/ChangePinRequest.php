<?php

namespace App\Core\Identity\Pin\Http\Requests;

use App\Core\Identity\Pin\PinRules;
use App\Core\Sync\Http\Requests\DeviceRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AUTH-06: POST pos/pin/change {user_id, pin, new_pin}, staff choose a new
 * PIN at the till (online), for example after an administrator set one for
 * them (`must_change`).
 */
class ChangePinRequest extends DeviceRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'pin' => ['required', 'string', 'max:6'],
            'new_pin' => ['required', 'string', 'max:6', 'different:pin', function (string $attribute, mixed $value, Closure $fail) {
                try {
                    PinRules::assertPin((string) $value, $attribute);
                } catch (ValidationException $e) {
                    $fail(collect($e->errors())->flatten()->first());
                }
            }],
        ];
    }
}
