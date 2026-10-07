<?php

namespace App\Core\Identity\Services;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\LoginEvent;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\NewDeviceSignIn;
use App\Core\Identity\Support\LoginIdentifier;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Password sign-in (AUTH-01) with lockout and new-device alerts (AUTH-10).
 *
 * The login is resolved to a tenant by auth_tenant_for_login (security
 * definer), the tenant context is set, and only then is the user read, under
 * RLS. Unknown logins still hash a password so they take as long as known ones.
 * The password is checked before any status is revealed.
 */
class Authenticate
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly LoginThrottle $throttle,
        private readonly Challenges $challenges,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function attempt(string $login, string $password, string $ip, string $userAgent, ?string $deviceName = null): SignInResult
    {
        $user = $this->findUser($login);

        if ($user === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(40)));

            return SignInResult::invalid();
        }

        if ($this->throttle->isLocked($user)) {
            $this->recordFailure($user, $ip, $userAgent, SignInResult::LOCKED);

            return SignInResult::locked($this->throttle->retryAfter($user));
        }

        if (! Hash::check($password, $user->password)) {
            $this->throttle->recordFailure($user);
            $this->recordFailure($user, $ip, $userAgent, SignInResult::INVALID);

            return SignInResult::invalid();
        }

        if ($user->status === User::STATUS_DEACTIVATED) {
            $this->recordFailure($user, $ip, $userAgent, SignInResult::DEACTIVATED);

            return SignInResult::deactivated();
        }

        if ($user->status === User::STATUS_PENDING) {
            $this->recordFailure($user, $ip, $userAgent, SignInResult::UNVERIFIED);
            [$channel, $destination] = $user->email !== null ? ['email', $user->email] : ['sms', $user->phone];
            // Past the send limits the account is still unverified; the
            // code already sent stays valid, so no new challenge id.
            try {
                $challenge = $this->challenges->issue($user, VerificationChallenge::PURPOSE_VERIFY_CONTACT, $channel, $destination);
            } catch (ApiException $e) {
                if ($e->getStatusCode() !== 429) {
                    throw $e;
                }

                return SignInResult::unverified(null, (int) ($e->getHeaders()['Retry-After'] ?? Challenges::SEND_COOLDOWN_SECONDS));
            }

            return SignInResult::unverified($challenge->id);
        }

        $user->forceFill(['failed_sign_ins' => 0, 'locked_until' => null])->saveQuietly();

        if ($user->hasTwoFactor()) {
            // AUTH-03: the token is issued once the second factor passes
            // (POST auth/two-factor/challenge). A TOTP challenge sends nothing.
            [$channel, $destination] = match (true) {
                $user->two_factor_method === TwoFactor::METHOD_SMS && $user->phone !== null => ['sms', $user->phone],
                $user->two_factor_method === TwoFactor::METHOD_SMS => ['email', $user->email],
                default => [null, null],
            };
            $challenge = $this->challenges->issue($user, VerificationChallenge::PURPOSE_TWO_FACTOR, $channel, $destination);

            return SignInResult::twoFactorRequired($challenge->id);
        }

        return SignInResult::ok($this->issueToken($user, $ip, $userAgent, $deviceName), $user);
    }

    /**
     * Sign $user in on a device: token, login event, audit entry, and an
     * alert when the device is new for a user who signed in before. A user
     * whose role requires two-factor but who has none gets a token that can
     * only enrol (AUTH-03). Requires $user's tenant context.
     */
    public function issueToken(User $user, string $ip, string $userAgent, ?string $deviceName = null): string
    {
        $fingerprint = LoginEvent::fingerprint($userAgent, $ip);
        $seen = LoginEvent::where('user_id', $user->id)->where('succeeded', true);
        $newDevice = (clone $seen)->exists() && ! (clone $seen)->where('fingerprint', $fingerprint)->exists();

        $plain = DB::transaction(function () use ($user, $ip, $userAgent, $deviceName, $fingerprint) {
            $user->forceFill(['last_sign_in_at' => now()])->saveQuietly();
            $this->logEvent($user, $ip, $userAgent, $fingerprint, succeeded: true);

            $token = $user->createDeviceToken($deviceName ?: ($userAgent ?: 'unknown'), $ip, $userAgent, $this->twoFactor->tokenAbilities($user));

            $this->auditor->record('auth.sign_in', $user, null, [
                'token_id' => $token->accessToken->getKey(),
                'device' => $token->accessToken->name,
            ], ['user_id' => $user->id]);

            return $token->plainTextToken;
        });

        if ($newDevice) {
            // Queued, to the address rather than the model: a worker has no
            // tenant context to reload the user under RLS.
            // The sign-in has committed: a queue that cannot be reached is
            // reported, never turned into an error.
            [$channel, $route] = $user->email !== null ? ['mail', $user->email] : ['sms', $user->phone];
            rescue(fn () => Notification::route($channel, $route)->notify(
                (new NewDeviceSignIn($user->name, $channel, $ip, $userAgent, now()))->locale($user->locale),
            ), report: true);
        }

        return $plain;
    }

    /**
     * The one user the login names, read under its tenant's context (which
     * is left set). A local phone number that matches users in more than one
     * country names nobody: neither is signed in.
     */
    public function findUser(string $login): ?User
    {
        $matches = [];

        foreach (LoginIdentifier::candidates($login) as $candidate) {
            $tenantId = DB::selectOne('select auth_tenant_for_login(?) as tenant_id', [$candidate])?->tenant_id;

            if ($tenantId !== null) {
                $matches[$candidate] = $tenantId;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $candidate = array_key_first($matches);
        $this->tenants->set($matches[$candidate]);

        return User::query()
            ->where(fn ($query) => $query->where('email', $candidate)->orWhere('phone', $candidate))
            ->first();
    }

    private function recordFailure(User $user, string $ip, string $userAgent, string $reason): void
    {
        DB::transaction(function () use ($user, $ip, $userAgent, $reason) {
            $this->logEvent($user, $ip, $userAgent, LoginEvent::fingerprint($userAgent, $ip), succeeded: false);
            $this->auditor->record('auth.sign_in_failed', $user, null, ['reason' => $reason]);
        });
    }

    private function logEvent(User $user, string $ip, string $userAgent, string $fingerprint, bool $succeeded): void
    {
        LoginEvent::create([
            'user_id' => $user->id,
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'user_agent' => $userAgent,
            'fingerprint' => $fingerprint,
            'succeeded' => $succeeded,
        ]);
    }
}
