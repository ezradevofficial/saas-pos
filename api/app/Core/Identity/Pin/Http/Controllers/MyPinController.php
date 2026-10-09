<?php

namespace App\Core\Identity\Pin\Http\Controllers;

use App\Core\Identity\Pin\Http\Requests\RemoveMyPinRequest;
use App\Core\Identity\Pin\Http\Requests\ShowMyPinRequest;
use App\Core\Identity\Pin\Http\Requests\UpdateMyPinRequest;
use App\Core\Identity\Pin\PasswordCheck;
use App\Core\Identity\Pin\Pins;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-06: the signed-in user's own POS PIN and staff card. Setting or
 * removing them takes the account password (PasswordCheck). Answers say
 * only whether a PIN and card are set.
 */
class MyPinController
{
    public function __construct(
        private readonly Pins $pins,
        private readonly PasswordCheck $password,
    ) {}

    public function show(ShowMyPinRequest $request): JsonResponse
    {
        // AUTH-08: whether a 6-digit PIN is required, so the form can say so before saving.
        return response()->json(['data' => [...$this->pins->status($request->user()), 'six_digits' => $this->pins->needsSixDigits($request->user())]]);
    }

    public function update(UpdateMyPinRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->password->confirm($user, (string) $request->validated('password'));
        $this->pins->set($user, (string) $request->validated('pin'), $request->cardInput());

        return response()->json(['message' => __('auth.pin.saved'), 'data' => $this->pins->status($user)]);
    }

    public function destroy(RemoveMyPinRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->password->confirm($user, (string) $request->validated('password'));
        $this->pins->clear($user);

        return response()->json(['message' => __('auth.pin.removed'), 'data' => $this->pins->status($user)]);
    }
}
