<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Identity\Http\Requests\ConfirmTwoFactorRequest;
use App\Core\Identity\Http\Requests\DisableTwoFactorRequest;
use App\Core\Identity\Http\Requests\VerifyRequest;
use App\Core\Identity\Http\Resources\UserResource;
use App\Core\Identity\Http\Responses\TokenResponse;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Services\Authenticate;
use App\Core\Identity\Services\Challenges;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * AUTH-03: completing a two-factor sign-in, and the signed-in user's own
 * enrolment (TOTP or SMS) and opt-out.
 */
class TwoFactorController
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly Challenges $challenges,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * POST auth/two-factor/challenge: the challenge from sign-in plus a code
     * (from the app, or the SMS sent at sign-in) completes the sign-in.
     */
    public function challenge(VerifyRequest $request, Authenticate $authenticate): JsonResponse
    {
        $id = (string) $request->string('challenge_id');
        $open = $this->challenges->findOpen($id, VerificationChallenge::PURPOSE_TWO_FACTOR) ?? throw Challenges::failure();

        $this->tenants->set($open->tenant_id);
        $user = User::find($open->user_id);

        if ($user?->status === User::STATUS_DEACTIVATED) {
            throw new ApiException(403, 'deactivated', __('auth.deactivated'));
        }

        if ($user === null || ! $user->isActive() || ! $user->hasTwoFactor()) {
            throw Challenges::failure();
        }

        // A TOTP challenge has no stored code: the app's code is checked.
        $check = $open->channel === null
            ? fn (VerificationChallenge $challenge, string $code) => $this->twoFactor->verifyTotp($user, $code)
            : null;

        $this->challenges->verify($id, (string) $request->string('code'), VerificationChallenge::PURPOSE_TWO_FACTOR, $check);

        $token = $authenticate->issueToken($user, (string) $request->ip(), (string) $request->userAgent(), $request->deviceName());

        return TokenResponse::make($token, $user, $request);
    }

    /** POST me/two-factor/totp: a new secret, as text, otpauth URL and QR code. */
    public function startTotp(Request $request): JsonResponse
    {
        return response()->json($this->twoFactor->startTotp($request->user()));
    }

    /** POST me/two-factor/totp/confirm */
    public function confirmTotp(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $this->twoFactor->confirmTotp($request->user(), (string) $request->string('code'));

        return $this->enabled($request);
    }

    /** POST me/two-factor/sms: sends a code to the user's verified phone. */
    public function startSms(Request $request): JsonResponse
    {
        $challenge = $this->twoFactor->startSms($request->user());

        return response()->json([
            'destination_masked' => Challenges::maskDestination($challenge->channel, $challenge->destination),
        ]);
    }

    /** POST me/two-factor/sms/confirm */
    public function confirmSms(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $this->twoFactor->confirmSms($request->user(), (string) $request->string('code'));

        return $this->enabled($request);
    }

    /** DELETE me/two-factor: needs the password. */
    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check((string) $request->string('password'), $user->password)) {
            throw new ApiException(422, 'invalid_password', __('auth.password.incorrect'), ['password' => [__('auth.password.incorrect')]]);
        }

        if ($user->two_factor_method !== null) {
            $this->twoFactor->disable($user);
        }

        return response()->json([
            'message' => __('auth.two_factor.disabled'),
            'user' => UserResource::make($user)->resolve($request),
        ]);
    }

    /**
     * Two-factor is on: a token that could only enrol now has full access,
     * since the user has just proved the second factor.
     */
    private function enabled(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ! $token->can('*')) {
            $token->forceFill(['abilities' => ['*']])->save();
        }

        return response()->json([
            'message' => __('auth.two_factor.enabled'),
            'user' => UserResource::make($request->user())->resolve($request),
        ]);
    }
}
