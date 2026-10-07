<?php

namespace App\Core\Identity\Services;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ScopeResolver;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * AUTH-03: the second factor, by authenticator app (TOTP) or SMS code.
 *
 * Enrolment stores the method (and the TOTP secret, encrypted) on the user,
 * but two-factor counts as enabled only once a valid code confirms it.
 * Starting again replaces an unconfirmed enrolment; an enabled user must
 * disable first. TOTP codes are accepted one step either side of now, and a
 * time step is accepted at most once (two_factor_last_used_step).
 *
 * Requires the user's tenant context.
 */
class TwoFactor
{
    /** The only ability of a token issued to a user who must enrol first. */
    public const ENROL_ABILITY = 'two-factor:enroll';

    public const METHOD_TOTP = 'totp';

    public const METHOD_SMS = 'sms';

    private const STEP_SECONDS = 30;

    private const WINDOW = 1;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly ScopeResolver $scopes,
        private readonly Challenges $challenges,
        private readonly Auditor $auditor,
    ) {}

    /** True when any role the user holds requires two-factor (RBAC roles.requires_two_factor). */
    public function required(User $user): bool
    {
        $roleIds = $this->scopes->roleIds($user);

        return $roleIds !== [] && Role::whereKey($roleIds)->where('requires_two_factor', true)->exists();
    }

    /** Signed in by password alone but required to have a second factor: the token may only enrol. */
    public function mustEnrol(User $user): bool
    {
        return ! $user->hasTwoFactor() && $this->required($user);
    }

    /** @return list<string> the abilities of a new token for $user */
    public function tokenAbilities(User $user): array
    {
        return $this->mustEnrol($user) ? [self::ENROL_ABILITY] : ['*'];
    }

    /**
     * Start (or restart) TOTP enrolment.
     *
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function startTotp(User $user): array
    {
        $this->assertNotEnabled($user);

        $secret = $this->google2fa->generateSecretKey(32);

        $user->forceFill([
            'two_factor_method' => self::METHOD_TOTP,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->saveQuietly();

        $url = $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email ?? $user->phone, $secret);

        return [
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_svg' => (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url),
        ];
    }

    /**
     * @throws ApiException 422 invalid_code, 422 two_factor_not_started, 429 too_many_requests
     */
    public function confirmTotp(User $user, string $code): void
    {
        $this->assertNotEnabled($user);

        if ($user->two_factor_method !== self::METHOD_TOTP || $user->two_factor_secret === null) {
            throw self::notStarted();
        }

        $this->challenges->assertWithinFailureBudget($user->id, VerificationChallenge::PURPOSE_TWO_FACTOR);

        if (! $this->verifyTotp($user, $code)) {
            $this->challenges->recordFailedCode($user->id, VerificationChallenge::PURPOSE_TWO_FACTOR);

            throw Challenges::failure();
        }

        $this->enable($user);
    }

    /**
     * Start (or restart) SMS enrolment: a code goes to the user's verified phone.
     *
     * @throws ApiException 422 phone_required, 429 too_many_requests
     */
    public function startSms(User $user): VerificationChallenge
    {
        $this->assertNotEnabled($user);

        if ($user->phone === null || $user->phone_verified_at === null) {
            throw new ApiException(422, 'phone_required', __('auth.two_factor.phone_required'), ['phone' => [__('auth.two_factor.phone_required')]]);
        }

        $user->forceFill([
            'two_factor_method' => self::METHOD_SMS,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->saveQuietly();

        return $this->challenges->issue($user, VerificationChallenge::PURPOSE_TWO_FACTOR, 'sms', $user->phone);
    }

    /**
     * @throws ApiException 422 invalid_code, 422 two_factor_not_started, 429 too_many_requests
     */
    public function confirmSms(User $user, string $code): void
    {
        $this->assertNotEnabled($user);

        $challenge = $user->two_factor_method === self::METHOD_SMS
            ? $this->challenges->latestOpen($user, VerificationChallenge::PURPOSE_TWO_FACTOR)
            : null;

        if ($challenge === null || $challenge->channel !== 'sms') {
            throw self::notStarted();
        }

        $this->challenges->verify($challenge->id, $code, VerificationChallenge::PURPOSE_TWO_FACTOR);

        $this->enable($user);
    }

    public function disable(User $user): void
    {
        DB::transaction(function () use ($user) {
            $method = $user->two_factor_method;

            $user->forceFill([
                'two_factor_method' => null,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
            ])->saveQuietly();

            $this->auditor->record('auth.two_factor_disabled', $user, ['method' => $method], null);
        });
    }

    /**
     * Check a TOTP code against the user's secret, one step either side of
     * now, and spend its time step so it is never accepted again.
     */
    public function verifyTotp(User $user, string $code): bool
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

        // Atomic, so two requests cannot spend the same step.
        $spent = User::whereKey($user->id)
            ->where(fn ($query) => $query->whereNull('two_factor_last_used_step')->orWhere('two_factor_last_used_step', '<', $step))
            ->toBase()
            ->update(['two_factor_last_used_step' => $step]);

        if ($spent === 0) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_step' => $step])->syncOriginalAttribute('two_factor_last_used_step');

        return true;
    }

    private function enable(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['two_factor_confirmed_at' => now()])->saveQuietly();

            $this->auditor->record('auth.two_factor_enabled', $user, null, ['method' => $user->two_factor_method]);
        });
    }

    private function assertNotEnabled(User $user): void
    {
        if ($user->hasTwoFactor()) {
            throw new ApiException(409, 'two_factor_already_enabled', __('auth.two_factor.already_enabled'));
        }
    }

    private static function notStarted(): ApiException
    {
        return new ApiException(422, 'two_factor_not_started', __('auth.two_factor.not_started'), ['code' => [__('auth.two_factor.not_started')]]);
    }
}
