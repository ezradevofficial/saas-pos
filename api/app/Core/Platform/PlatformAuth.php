<?php

namespace App\Core\Platform;

use App\Core\Http\ApiException;
use App\Core\Identity\Services\LoginThrottle;
use App\Core\Platform\Models\PlatformAccessToken;
use App\Core\Platform\Models\PlatformUser;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Platform staff sign-in (ADR 013), on the tenant rules (AUTH-01, AUTH-03,
 * AUTH-10) but stricter: two-factor by authenticator app is required.
 *
 * - The first account comes from `platform:user`, which prints a one-time
 *   setup link. Setting the password there starts TOTP enrolment and gives
 *   a token that can only enrol; a confirmed code lifts it to full access.
 * - Sign-in checks the password (5 failures lock for 15 minutes), then asks
 *   for a TOTP code against a short-lived encrypted challenge. A staff
 *   member without two-factor gets an enrolment token only.
 * - Every attempt, success or failure, is audited.
 */
class PlatformAuth
{
    public const CHALLENGE_MINUTES = 5;

    public const SETUP_HOURS = 24;

    private const STEP_SECONDS = 30;

    private const WINDOW = 1;

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly PlatformTokens $tokens,
        private readonly PlatformAuditor $audit,
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * A new staff member, or a fresh setup for an existing one (their
     * password, two-factor and tokens are cleared). Returns the plain setup
     * token for the one-time link.
     */
    public function invite(string $email, string $name, string $role, bool $reset = false): string
    {
        $plain = Str::random(48);

        DB::transaction(function () use ($email, $name, $role, $reset, $plain) {
            $user = PlatformUser::where('email', $email)->first();

            if ($user !== null && ! $reset) {
                throw new ApiException(409, 'platform_user_exists', __('platform.user_exists'));
            }

            $before = $user === null ? null : ['status' => $user->status, 'role' => $user->role];
            $user ??= new PlatformUser(['email' => $email]);
            $user->fill(['name' => $name, 'role' => $role]);
            $user->forceFill([
                'password' => null,
                'status' => PlatformUser::STATUS_PENDING,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
                'failed_sign_ins' => 0,
                'locked_until' => null,
                'setup_token_hash' => hash('sha256', $plain),
                'setup_expires_at' => now()->addHours(self::SETUP_HOURS),
            ])->save();

            $this->tokens->revokeAll($user);
            $this->audit->record($before === null ? 'platform.user.created' : 'platform.user.reset', $user, $before, ['email' => $user->email, 'role' => $user->role, 'status' => $user->status]);
        });

        return $plain;
    }

    /** The staff member a live setup token belongs to, or null. */
    public function pendingSetup(string $plain): ?PlatformUser
    {
        return PlatformUser::where('setup_token_hash', hash('sha256', $plain))
            ->where('setup_expires_at', '>', now())
            ->where('status', PlatformUser::STATUS_PENDING)
            ->first();
    }

    /**
     * Set the password from the setup link and start TOTP enrolment.
     *
     * @return array{token: string, user: PlatformUser, two_factor: array{secret: string, otpauth_url: string, qr_svg: string}}
     */
    public function completeSetup(string $plain, string $password, ?string $ip, ?string $userAgent): array
    {
        return DB::transaction(function () use ($plain, $password, $ip, $userAgent) {
            $user = $this->pendingSetup($plain) ?? throw self::setupInvalid();
            $user->forceFill(['password' => $password, 'setup_token_hash' => null, 'setup_expires_at' => null])->save();

            $twoFactor = $this->startTotp($user);
            $token = $this->tokens->issue($user, [PlatformAccessToken::ENROL], $ip, $userAgent);
            $this->audit->record('platform.auth.setup_completed', $user, actor: $user);

            return ['token' => $token, 'user' => $user, 'two_factor' => $twoFactor];
        });
    }

    /**
     * Password step. Answers ['challenge' => ...] when a code is due, or
     * ['token' => ...] limited to enrolment when two-factor is not set up.
     *
     * @return array{challenge?: string, token?: string, user?: PlatformUser}
     *
     * @throws ApiException 422 invalid_credentials, 423 locked, 403 deactivated
     */
    public function signIn(string $email, string $password, ?string $ip, ?string $userAgent): array
    {
        $user = PlatformUser::where('email', $email)->first();

        if ($user === null || $user->password === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(40)));

            throw self::invalidCredentials();
        }

        $this->assertNotLocked($user);

        if (! Hash::check($password, $user->password)) {
            $this->recordFailure($user, 'invalid_password');

            throw self::invalidCredentials();
        }

        if ($user->status === PlatformUser::STATUS_DEACTIVATED) {
            $this->audit->record('platform.auth.sign_in_failed', $user, null, ['reason' => 'deactivated'], $user);

            throw new ApiException(403, 'deactivated', __('platform.auth.deactivated'));
        }

        if (! $user->hasTwoFactor()) {
            $token = DB::transaction(function () use ($user, $ip, $userAgent) {
                $user->forceFill(['failed_sign_ins' => 0, 'locked_until' => null])->save();
                $this->audit->record('platform.auth.enrolment_sign_in', $user, actor: $user);

                return $this->tokens->issue($user, [PlatformAccessToken::ENROL], $ip, $userAgent);
            });

            return ['token' => $token, 'user' => $user];
        }

        return ['challenge' => Crypt::encryptString(json_encode([
            'uid' => $user->id,
            'exp' => now()->addMinutes(self::CHALLENGE_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR))];
    }

    /**
     * Code step: a TOTP code for the challenge from signIn().
     *
     * @return array{token: string, user: PlatformUser}
     */
    public function verifyChallenge(string $challenge, string $code, ?string $ip, ?string $userAgent): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($challenge), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $payload = null;
        }

        $user = is_array($payload) && is_string($payload['uid'] ?? null) && (int) ($payload['exp'] ?? 0) >= now()->getTimestamp()
            ? PlatformUser::find($payload['uid'])
            : null;

        if ($user === null || ! $user->hasTwoFactor() || $user->status === PlatformUser::STATUS_DEACTIVATED) {
            throw new ApiException(422, 'challenge_expired', __('platform.auth.challenge_expired'), ['challenge' => [__('platform.auth.challenge_expired')]]);
        }

        $this->assertNotLocked($user);

        if (! $this->verifyTotp($user, $code)) {
            $this->recordFailure($user, 'invalid_code');

            throw self::invalidCode();
        }

        return DB::transaction(function () use ($user, $ip, $userAgent) {
            $user->forceFill(['failed_sign_ins' => 0, 'locked_until' => null, 'last_sign_in_at' => now()])->save();
            $token = $this->tokens->issue($user, [PlatformAccessToken::FULL], $ip, $userAgent);
            $this->audit->record('platform.auth.sign_in', $user, null, ['token_id' => $user->currentAccessToken()?->id], $user);

            return ['token' => $token, 'user' => $user];
        });
    }

    /**
     * Start (or restart) enrolment. Refused once two-factor is confirmed.
     *
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function startTotp(PlatformUser $user): array
    {
        if ($user->hasTwoFactor()) {
            throw new ApiException(409, 'two_factor_already_enabled', __('auth.two_factor.already_enabled'));
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_last_used_step' => null])->save();

        $url = $this->google2fa->getQRCodeUrl(config('app.name').' platform', $user->email, $secret);

        return [
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_svg' => (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url),
        ];
    }

    /** Confirm enrolment with a code: the account becomes active and the token full. */
    public function confirmTotp(PlatformUser $user, string $code): void
    {
        if ($user->hasTwoFactor()) {
            throw new ApiException(409, 'two_factor_already_enabled', __('auth.two_factor.already_enabled'));
        }

        if ($user->two_factor_secret === null) {
            throw new ApiException(422, 'two_factor_not_started', __('auth.two_factor.not_started'), ['code' => [__('auth.two_factor.not_started')]]);
        }

        $this->assertNotLocked($user);

        if (! $this->verifyTotp($user, $code)) {
            $this->recordFailure($user, 'invalid_enrolment_code');

            throw self::invalidCode();
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'two_factor_confirmed_at' => now(),
                'status' => PlatformUser::STATUS_ACTIVE,
                'failed_sign_ins' => 0,
                'last_sign_in_at' => now(),
            ])->save();

            if (($token = $user->currentAccessToken()) !== null) {
                $this->tokens->upgrade($token);
            }

            $this->audit->record('platform.auth.two_factor_enabled', $user, null, ['method' => 'totp'], $user);
        });
    }

    public function signOut(PlatformUser $user): void
    {
        DB::transaction(function () use ($user) {
            $user->currentAccessToken()?->delete();
            $this->audit->record('platform.auth.sign_out', $user, actor: $user);
        });
    }

    /** One step either side of now; a time step is accepted only once. */
    private function verifyTotp(PlatformUser $user, string $code): bool
    {
        if ($user->two_factor_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $step = $this->google2fa->verifyKey(
            $user->two_factor_secret,
            $code,
            self::WINDOW,
            intdiv(now()->getTimestamp(), self::STEP_SECONDS),
            $user->two_factor_last_used_step ?? 0,
        );

        if (! is_int($step)) {
            return false;
        }

        $spent = PlatformUser::whereKey($user->id)
            ->where(fn ($query) => $query->whereNull('two_factor_last_used_step')->orWhere('two_factor_last_used_step', '<', $step))
            ->toBase()
            ->update(['two_factor_last_used_step' => $step]);

        if ($spent === 0) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_step' => $step])->syncOriginalAttribute('two_factor_last_used_step');

        return true;
    }

    private function assertNotLocked(PlatformUser $user): void
    {
        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            $this->audit->record('platform.auth.sign_in_failed', $user, null, ['reason' => 'locked'], $user);
            $seconds = max(1, (int) ceil(now()->diffInSeconds($user->locked_until, true)));

            throw new ApiException(423, 'locked', LoginThrottle::lockedMessage($seconds), extra: ['retry_after' => $seconds], headers: ['Retry-After' => $seconds]);
        }
    }

    /** Count a failure atomically; the fifth locks the account for 15 minutes. */
    private function recordFailure(PlatformUser $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason) {
            $row = DB::selectOne(
                'update platform_users set
                    failed_sign_ins = case when failed_sign_ins + 1 >= ? then 0 else failed_sign_ins + 1 end,
                    locked_until = case when failed_sign_ins + 1 >= ? then ? else locked_until end
                 where id = ?
                 returning failed_sign_ins, locked_until',
                [LoginThrottle::MAX_FAILURES, LoginThrottle::MAX_FAILURES, now()->addMinutes(LoginThrottle::LOCK_MINUTES)->format('Y-m-d H:i:s.uP'), $user->id],
            );

            $user->forceFill(['failed_sign_ins' => $row->failed_sign_ins, 'locked_until' => $row->locked_until])->syncOriginal();
            $this->audit->record('platform.auth.sign_in_failed', $user, null, ['reason' => $reason], $user);
        });
    }

    private static function invalidCredentials(): ApiException
    {
        return new ApiException(422, 'invalid_credentials', __('platform.auth.invalid_credentials'), ['email' => [__('platform.auth.invalid_credentials')]]);
    }

    private static function invalidCode(): ApiException
    {
        return new ApiException(422, 'invalid_code', __('platform.auth.invalid_code'), ['code' => [__('platform.auth.invalid_code')]]);
    }

    public static function setupInvalid(): ApiException
    {
        return new ApiException(404, 'setup_link_invalid', __('platform.auth.setup_link_invalid'));
    }
}
