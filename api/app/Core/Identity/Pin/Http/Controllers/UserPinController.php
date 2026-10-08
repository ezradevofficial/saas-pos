<?php

namespace App\Core\Identity\Pin\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\Http\Requests\UserPinRequest;
use App\Core\Identity\Pin\Pins;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-06: an administrator (`core.user.edit` over every scope of the
 * user) gives a user a new POS PIN, for example when they forgot it or
 * are locked out, or removes it. The PIN is never returned or readable.
 */
class UserPinController
{
    public function __construct(private readonly Pins $pins) {}

    public function update(UserPinRequest $request, User $user): JsonResponse
    {
        $this->pins->set($user, (string) $request->validated('pin'), $request->cardInput(), $request->user());

        return response()->json(['message' => __('auth.pin.reset'), 'data' => $this->pins->status($user)]);
    }

    public function destroy(UserPinRequest $request, User $user): JsonResponse
    {
        $this->pins->clear($user, $request->user());

        return response()->json(['message' => __('auth.pin.removed'), 'data' => $this->pins->status($user)]);
    }
}
