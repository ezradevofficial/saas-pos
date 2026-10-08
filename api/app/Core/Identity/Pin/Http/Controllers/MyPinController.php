<?php

namespace App\Core\Identity\Pin\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\Http\Requests\RemoveMyPinRequest;
use App\Core\Identity\Pin\Http\Requests\ShowMyPinRequest;
use App\Core\Identity\Pin\Http\Requests\UpdateMyPinRequest;
use App\Core\Identity\Pin\Pins;
use App\Core\Identity\Services\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * AUTH-06: the signed-in user's own POS PIN and staff card. Setting or
 * removing them takes the account password (a stolen session alone cannot
 * open the tills); wrong passwords count towards the account lockout
 * (AUTH-10). Answers say only whether a PIN and card are set.
 */
class MyPinController
{
    public function __construct(
        private readonly Pins $pins,
        private readonly LoginThrottle $throttle,
    ) {}

    public function show(ShowMyPinRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->pins->status($request->user())]);
    }

    public function update(UpdateMyPinRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->confirmPassword($user, (string) $request->validated('password'));
        $this->pins->set($user, (string) $request->validated('pin'), $request->cardInput());

        return response()->json(['message' => __('auth.pin.saved'), 'data' => $this->pins->status($user)]);
    }

    public function destroy(RemoveMyPinRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->confirmPassword($user, (string) $request->validated('password'));
        $this->pins->clear($user);

        return response()->json(['message' => __('auth.pin.removed'), 'data' => $this->pins->status($user)]);
    }

    private function confirmPassword(User $user, string $password): void
    {
        if ($this->throttle->isLocked($user)) {
            $seconds = $this->throttle->retryAfter($user);

            throw new ApiException(423, 'locked', LoginThrottle::lockedMessage($seconds), headers: ['Retry-After' => $seconds]);
        }

        if (! Hash::check($password, $user->password)) {
            $this->throttle->recordFailure($user);

            throw new ApiException(422, 'invalid_password', __('auth.password.incorrect'), ['password' => [__('auth.password.incorrect')]]);
        }
    }
}
