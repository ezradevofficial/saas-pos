<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Identity\Http\Requests\ResendCodeRequest;
use App\Core\Identity\Http\Requests\VerifyRequest;
use App\Core\Identity\Http\Responses\TokenResponse;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Services\Authenticate;
use App\Core\Identity\Services\Challenges;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** POST auth/verify and auth/verify/resend (AUTH-01). */
class VerifyController
{
    public function __construct(
        private readonly Challenges $challenges,
        private readonly TenantContext $tenants,
    ) {}

    public function verify(VerifyRequest $request, Authenticate $authenticate): JsonResponse
    {
        $challenge = $this->challenges->verify(
            $request->string('challenge_id'),
            $request->string('code'),
            VerificationChallenge::PURPOSE_VERIFY_CONTACT,
        );

        $this->tenants->set($challenge->tenant_id);
        $user = User::findOrFail($challenge->user_id);

        if ($user->status === User::STATUS_DEACTIVATED) {
            throw new ApiException(403, 'deactivated', __('auth.deactivated'));
        }

        $user->forceFill([
            $challenge->channel === 'sms' ? 'phone_verified_at' : 'email_verified_at' => now(),
            'status' => User::STATUS_ACTIVE,
        ])->save();

        $token = $authenticate->issueToken($user, (string) $request->ip(), (string) $request->userAgent(), $request->deviceName());

        return TokenResponse::make($token, $user, $request);
    }

    public function resend(ResendCodeRequest $request): JsonResponse
    {
        $old = $this->challenges->findOpen($request->string('challenge_id'), VerificationChallenge::PURPOSE_VERIFY_CONTACT);

        if ($old !== null) {
            $this->tenants->set($old->tenant_id);
            $user = User::find($old->user_id);
        }

        if ($old === null || ! isset($user) || $user->status !== User::STATUS_PENDING) {
            throw new ApiException(422, 'invalid_code', __('auth.code.invalid'), ['challenge_id' => [__('auth.code.invalid')]]);
        }

        // An exhausted challenge is not renewed: a new code needs a sign-in
        // (with the password), so codes cannot be guessed resend by resend.
        if ($this->challenges->isExhausted($old)) {
            throw new ApiException(422, 'challenge_exhausted', __('auth.code.exhausted'), ['challenge_id' => [__('auth.code.exhausted')]]);
        }

        // Consumes $old; refused with 429 past the send limits.
        $challenge = $this->challenges->issue($user, $old->purpose, $old->channel, $old->destination);

        return response()->json([
            'challenge_id' => $challenge->id,
            'destination_masked' => Challenges::maskDestination($challenge->channel, $challenge->destination),
        ]);
    }
}
