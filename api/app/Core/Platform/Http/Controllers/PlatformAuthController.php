<?php

namespace App\Core\Platform\Http\Controllers;

use App\Core\Platform\Http\Requests\PlatformChallengeRequest;
use App\Core\Platform\Http\Requests\PlatformConfirmTotpRequest;
use App\Core\Platform\Http\Requests\PlatformSelfRequest;
use App\Core\Platform\Http\Requests\PlatformSetupRequest;
use App\Core\Platform\Http\Requests\PlatformSignInRequest;
use App\Core\Platform\Http\Requests\ShowPlatformSetupRequest;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Platform staff sign-in, setup link and two-factor enrolment (ADR 013).
 * Answers never include tenant data.
 */
class PlatformAuthController extends Controller
{
    public function __construct(private readonly PlatformAuth $auth) {}

    /** POST platform/auth/sign-in: a challenge for the code, or an enrolment token. */
    public function signIn(PlatformSignInRequest $request): JsonResponse
    {
        $result = $this->auth->signIn(mb_strtolower(trim($request->string('email'))), (string) $request->input('password'), $request->ip(), $request->userAgent());

        if (isset($result['challenge'])) {
            return response()->json(['status' => 'two_factor_required', 'challenge' => $result['challenge']]);
        }

        return response()->json(['status' => 'enrolment_required', 'token' => $result['token'], 'user' => self::user($result['user'])]);
    }

    /** POST platform/auth/two-factor: the code for a sign-in challenge gives a full token. */
    public function challenge(PlatformChallengeRequest $request): JsonResponse
    {
        $result = $this->auth->verifyChallenge((string) $request->input('challenge'), (string) $request->input('code'), $request->ip(), $request->userAgent());

        return response()->json(['status' => 'signed_in', 'token' => $result['token'], 'user' => self::user($result['user'])]);
    }

    /** GET platform/auth/setup/{token}: whose setup link this is. */
    public function showSetup(ShowPlatformSetupRequest $request, string $token): JsonResponse
    {
        $user = $this->auth->pendingSetup($token) ?? throw PlatformAuth::setupInvalid();

        return response()->json(['data' => ['name' => $user->name, 'email' => $user->email]]);
    }

    /** POST platform/auth/setup/{token}: set the password; enrolment starts at once. */
    public function setup(PlatformSetupRequest $request, string $token): JsonResponse
    {
        $result = $this->auth->completeSetup($token, (string) $request->input('password'), $request->ip(), $request->userAgent());

        return response()->json([
            'status' => 'enrolment_required',
            'token' => $result['token'],
            'user' => self::user($result['user']),
            'two_factor' => $result['two_factor'],
        ]);
    }

    /** GET platform/me */
    public function me(PlatformSelfRequest $request): JsonResponse
    {
        return response()->json(['data' => self::user($request->staff())]);
    }

    /** POST platform/me/two-factor/totp: a new secret while two-factor is not confirmed. */
    public function startTotp(PlatformSelfRequest $request): JsonResponse
    {
        return response()->json($this->auth->startTotp($request->staff()));
    }

    /** POST platform/me/two-factor/totp/confirm: the token in use becomes a full token. */
    public function confirmTotp(PlatformConfirmTotpRequest $request): JsonResponse
    {
        $user = $request->staff();
        $this->auth->confirmTotp($user, (string) $request->input('code'));

        return response()->json(['status' => 'signed_in', 'user' => self::user($user->refresh())]);
    }

    /** POST platform/auth/sign-out: ends this session only. */
    public function signOut(PlatformSelfRequest $request): JsonResponse
    {
        $this->auth->signOut($request->staff());

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    public static function user(PlatformUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'two_factor_enabled' => $user->hasTwoFactor(),
            'abilities' => $user->status === PlatformUser::STATUS_ACTIVE ? $user->abilities() : [],
            'last_sign_in_at' => $user->last_sign_in_at?->toIso8601String(),
        ];
    }
}
