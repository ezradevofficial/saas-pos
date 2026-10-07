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
    /** Every forgot and reset request takes at least this long, found or not. */
    public const MIN_MICROSECONDS = 300_000;

    public function __construct(
        private readonly Authenticate $authenticate,
        private readonly Challenges $challenges,
        private readonly Auditor $auditor,
    ) {}

    /**
     * POST auth/password/forgot: always 202 with the same body, so the
     * answer never tells whether the login exists. Only a verified email or
     * phone receives a code. The code is delivered after the response, and
     * the work before it is timeboxed.
     */
    public function forgot(ForgotPasswordRequest $request, Timebox $timebox): JsonResponse
    {
        $login = (string) $request->string('login');

        $timebox->call(function () use ($login) {
            $user = $this->authenticate->findUser($login);

            if ($user === null || ! $user->isActive()) {
                return;
            }

            [$channel, $destination, $verified] = str_contains($login, '@')
                ? ['email', $user->email, $user->email_verified_at !== null]
                : ['sms', $user->phone, $user->phone_verified_at !== null];

            if (! $verified) {
                return;
            }

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
        }, self::MIN_MICROSECONDS);

        return response()->json(['message' => __('auth.password_reset.sent')], 202);
    }

    /**
     * POST auth/password/reset: sets the new password, ends every session
     * and clears the lockout. No token: the user signs in again.
     *
     * The code is checked first, and every wrong case (unknown login, no
     * open code, wrong, spent or expired code) gets the same invalid_code
     * answer, so the response never tells whether the login exists. The
     * tenant's password policy (AUTH-02) applies only to a right code, which
     * stays open until a password passes.
     */
    public function reset(ResetPasswordRequest $request, Timebox $timebox): JsonResponse
    {
        $timebox->call(function () use ($request) {
            $user = $this->authenticate->findUser((string) $request->string('login'));
            $password = (string) $request->string('password');

            $open = $user !== null && $user->isActive()
                ? $this->challenges->latestOpen($user, VerificationChallenge::PURPOSE_PASSWORD_RESET)
                : null;

            if ($open === null) {
                throw Challenges::failure();
            }

            try {
                $challenge = $this->challenges->check($open->id, (string) $request->string('code'), VerificationChallenge::PURPOSE_PASSWORD_RESET);
            } catch (ApiException $e) {
                throw $e->errorCode === 'invalid_code' ? Challenges::failure() : $e;
            }

            Validator::make(
                ['password' => $password],
                ['password' => PasswordPolicy::rules(Tenant::find($user->tenant_id))],
            )->validate();

            DB::transaction(function () use ($user, $password, $challenge) {
                $this->challenges->consume($challenge);

                $user->forceFill([
                    'password' => $password,
                    'failed_sign_ins' => 0,
                    'locked_until' => null,
                ])->saveQuietly();

                $revoked = $user->tokens()->delete();

                $this->auditor->record('auth.password_reset', $user, null, ['sessions_revoked' => $revoked], ['user_id' => $user->id]);
            });
        }, self::MIN_MICROSECONDS);

        return response()->json(['message' => __('auth.password_reset.done')]);
    }
}
