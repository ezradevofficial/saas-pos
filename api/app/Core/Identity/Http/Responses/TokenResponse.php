<?php

namespace App\Core\Identity\Http\Responses;

use App\Core\Identity\Http\Resources\UserResource;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The answer to a completed sign-in: the token, the user, and whether the
 * token can only enrol a second factor (AUTH-03).
 */
final class TokenResponse
{
    public static function make(string $token, User $user, Request $request): JsonResponse
    {
        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user)->resolve($request),
            'two_factor_enrollment_required' => app(TwoFactor::class)->mustEnrol($user),
        ]);
    }
}
