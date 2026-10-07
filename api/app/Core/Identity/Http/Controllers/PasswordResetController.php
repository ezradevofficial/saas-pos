<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Http\Requests\ForgotPasswordRequest;
use App\Core\Identity\Http\Requests\ResetPasswordRequest;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Services\Authenticate;
use App\Core\Identity\Services\Challenges;
use App\Core\Identity\Services\PasswordPolicy;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;

use function Illuminate\Support\defer;

/**
 * AUTH-04: password reset by a one-time code (6 digits, 30 minutes, single
 * use, 5 attempts) sent to the login: by email for an email address, by SMS
 * for a phone number.
 */
class PasswordResetController
{
    /** Every forgot request takes at least this long, found or not. */
    public const FORGOT_MIN_MICROSECONDS = 300_000;

    public function __construct(
        private readonly Authenticate $authenticate,
        private readonly Challenges $challenges,
        private readonly Auditor $auditor,
    ) {}

    /**
     * POST auth/password/forgot: always 202 with the same body, so the
     * answer never tells whether the login exists. The code is delivered
     * after the response, and the work before it is timeboxed.
     */
    public function forgot(ForgotPasswordRequest $request, Timebox $timebox): JsonResponse
    {
        $login = (string) $request->string('login');

        $timebox->call(function () use ($login) {
            $user = $this->authenticate->findUser($login);

            if ($user === null || ! $user->isActive()) {
                return;
            }

            [$channel, $destination] = str_contains($login, '@') ? ['email', $user->email] : ['sms', $user->phone];

            try {
                [$challenge, $code] = $this->challenges->create($user, VerificationChallenge::PURPOSE_PASSWORD_RESET, $channel, $destination);
            } catch (ApiException $e) {
                // Past the send limits nothing is sent; the answer is the same.
                if ($e->getStatusCode() === 429) {
                    return;
                }

                throw $e;
            }

            defer(fn () => $this->challenges->send($user, $challenge, $code));
        }, self::FORGOT_MIN_MICROSECONDS);

        return response()->json(['message' => __('auth.password_reset.sent')], 202);
    }

    /**
     * POST auth/password/reset: sets the new password, ends every session
     * and clears the lockout. No token: the user signs in again.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $user = $this->authenticate->findUser((string) $request->string('login'));
        $password = (string) $request->string('password');

        Validator::make(
            ['password' => $password],
            ['password' => PasswordPolicy::rules($user === null ? null : Tenant::find($user->tenant_id))],
        )->validate();

        $challenge = $user !== null && $user->isActive()
            ? $this->challenges->latestOpen($user, VerificationChallenge::PURPOSE_PASSWORD_RESET)
            : null;

        if ($challenge === null) {
            throw Challenges::failure();
        }

        $this->challenges->verify($challenge->id, (string) $request->string('code'), VerificationChallenge::PURPOSE_PASSWORD_RESET);

        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => $password,
                'failed_sign_ins' => 0,
                'locked_until' => null,
            ])->saveQuietly();

            $revoked = $user->tokens()->delete();

            $this->auditor->record('auth.password_reset', $user, null, ['sessions_revoked' => $revoked], ['user_id' => $user->id]);
        });

        return response()->json(['message' => __('auth.password_reset.done')]);
    }
}
