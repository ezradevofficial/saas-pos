<?php

namespace App\Core\Platform;

use App\Core\Platform\Models\PlatformAccessToken;
use App\Core\Platform\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Platform bearer tokens (ADR 013). A token reads `plat_<uuid>|<secret>`:
 * the prefix makes Sanctum's lookup (which wants a bare UUID before the
 * bar) refuse it on every tenant route, and this guard refuses any token
 * without it. Only the sha256 of the secret is stored. Full tokens last
 * `platform.token_minutes`; enrolment tokens 30 minutes.
 */
class PlatformTokens
{
    public const PREFIX = 'plat_';

    private const ENROL_MINUTES = 30;

    /** last_used_at is written at most this often per token. */
    private const TOUCH_SECONDS = 60;

    /** @param  list<string>  $abilities */
    public function issue(PlatformUser $user, array $abilities, ?string $ip, ?string $userAgent): string
    {
        $secret = Str::random(48);
        $minutes = in_array(PlatformAccessToken::FULL, $abilities, true) ? (int) config('platform.token_minutes', 720) : self::ENROL_MINUTES;

        $token = PlatformAccessToken::create([
            'platform_user_id' => $user->id,
            'token' => hash('sha256', $secret),
            'abilities' => array_values($abilities),
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 1024),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $user->withAccessToken($token);

        return self::PREFIX.$token->id.'|'.$secret;
    }

    /** The `platform` guard: the staff member a request's bearer token names, or null. */
    public function authenticate(Request $request): ?PlatformUser
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || ! str_starts_with($bearer, self::PREFIX) || ! str_contains($bearer, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', substr($bearer, strlen(self::PREFIX)), 2);

        if ($secret === '' || ! Str::isUuid($id)) {
            return null;
        }

        $token = PlatformAccessToken::with('user')->find($id);

        if ($token === null || ! hash_equals($token->token, hash('sha256', $secret)) || $token->expires_at->isPast()) {
            return null;
        }

        $user = $token->user;

        if ($user === null || $user->status === PlatformUser::STATUS_DEACTIVATED) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now(), true) >= self::TOUCH_SECONDS) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $user->withAccessToken($token);
    }

    /** Lift an enrolment token to full access once two-factor is confirmed. */
    public function upgrade(PlatformAccessToken $token): void
    {
        $token->forceFill([
            'abilities' => [PlatformAccessToken::FULL],
            'expires_at' => now()->addMinutes((int) config('platform.token_minutes', 720)),
        ])->save();
    }

    public function revokeAll(PlatformUser $user): void
    {
        $user->tokens()->delete();
    }
}
