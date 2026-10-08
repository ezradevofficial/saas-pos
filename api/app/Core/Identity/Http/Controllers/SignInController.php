<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Identity\Http\Requests\SignInRequest;
use App\Core\Identity\Http\Responses\TokenResponse;
use App\Core\Identity\Services\Authenticate;
use App\Core\Identity\Services\LoginThrottle;
use App\Core\Identity\Services\SignInResult;
use Illuminate\Http\JsonResponse;

/** POST auth/sign-in (AUTH-01, AUTH-10). */
class SignInController
{
    public function __invoke(SignInRequest $request, Authenticate $authenticate): JsonResponse
    {
        $result = $authenticate->attempt(
            $request->string('login'),
            $request->string('password'),
            (string) $request->ip(),
            (string) $request->userAgent(),
            $request->deviceName(),
        );

        return match ($result->status) {
            SignInResult::OK => TokenResponse::make($result->token, $result->user, $request),
            SignInResult::TWO_FACTOR_REQUIRED => response()->json([
                'status' => SignInResult::TWO_FACTOR_REQUIRED,
                'challenge_id' => $result->challengeId,
            ]),
            SignInResult::LOCKED => throw new ApiException(
                423, 'locked', LoginThrottle::lockedMessage($result->retryAfter),
                headers: ['Retry-After' => $result->retryAfter],
            ),
            // Inside the send limits no new code goes out: the earlier one
            // still works, or the user waits.
            SignInResult::UNVERIFIED => throw new ApiException(
                403, 'unverified', __($result->challengeId === null ? 'auth.unverified_wait' : 'auth.unverified'),
                extra: ['challenge_id' => $result->challengeId],
                headers: $result->retryAfter === null ? [] : ['Retry-After' => $result->retryAfter],
            ),
            SignInResult::DEACTIVATED => throw new ApiException(403, 'deactivated', __('auth.deactivated')),
            default => throw new ApiException(422, 'invalid_credentials', __('auth.failed'), ['login' => [__('auth.failed')]]),
        };
    }
}
