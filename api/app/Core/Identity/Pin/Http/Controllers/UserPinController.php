<?php

namespace App\Core\Identity\Pin\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\Http\Requests\UserPinRequest;
use App\Core\Identity\Pin\PasswordCheck;
use App\Core\Identity\Pin\Pins;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-06: an administrator (`core.user.edit` over every scope of the
 * user) gives a user a new POS PIN, for example when they forgot it or
 * are locked out, or removes it. A PIN set for someone else must be
 * changed at the till first (`must_change`). Administrators acting on
 * themselves confirm their password, as on me/pos-pin. The PIN is never
 * returned or readable.
 */
class UserPinController
{
    public function __construct(
        private readonly Pins $pins,
        private readonly PasswordCheck $password,
    ) {}

    public function update(UserPinRequest $request, User $user): JsonResponse
    {
        $this->confirmIfSelf($request, $user);
        $this->pins->set($user, (string) $request->validated('pin'), $request->cardInput(), $request->user());

        return response()->json(['message' => __('auth.pin.reset'), 'data' => $this->pins->status($user)]);
    }

    public function destroy(UserPinRequest $request, User $user): JsonResponse
    {
        $this->confirmIfSelf($request, $user);
        $this->pins->clear($user, $request->user());

        return response()->json(['message' => __('auth.pin.removed'), 'data' => $this->pins->status($user)]);
    }

    private function confirmIfSelf(UserPinRequest $request, User $user): void
    {
        if ($request->user()->is($user)) {
            $this->password->confirm($user, (string) $request->validated('password'));
        }
    }
}
