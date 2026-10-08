<?php

namespace App\Core\Identity\Pin\Http\Requests;

use App\Core\Identity\Pin\Pins;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\Http\Requests\DeviceRequest;
use Illuminate\Validation\Rule;

/**
 * AUTH-08: POST pos/override {manager_user_id, pin | card, permission,
 * cashier_user_id?, reference}, a manager authorises an action on the
 * cashier's device while it is online. `permission` is the catalogue
 * permission the action needs (`pos.sale.void`); `reference` the record
 * it is for (a sale or line id), which the token is bound to.
 */
class OverrideRequest extends DeviceRequest
{
    public function rules(): array
    {
        return [
            'manager_user_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'pin' => ['required_without:card', 'prohibits:card', 'string', 'max:6'],
            'card' => ['required_without:pin', 'string', 'max:64'],
            'permission' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! app(PermissionRegistry::class)->has($value)) {
                    $fail(__('auth.override.unknown_permission'));
                }
            }],
            'cashier_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')],
            // The sale or line the override is for: tokens are bound to it.
            'reference' => ['required', 'string', 'max:100'],
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
