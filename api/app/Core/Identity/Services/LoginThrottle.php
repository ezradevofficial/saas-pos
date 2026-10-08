<?php

namespace App\Core\Identity\Services;

use App\Core\Identity\Models\User;
use App\Core\Identity\Support\LoginIdentifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AUTH-10: rate limits (10 a minute per IP, 5 a minute per login) and the
 * account lockout (5 consecutive failures lock it for 15 minutes).
 */
class LoginThrottle
{
    /** AUTH-10: "Try again in N minute(s)", rounded up, pluralised per locale. */
    public static function lockedMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return trans_choice('auth.locked', $minutes, ['minutes' => $minutes]);
    }

    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public const PER_IP_PER_MINUTE = 10;

    public const PER_LOGIN_PER_MINUTE = 5;

    public static function registerLimiters(): void
    {
        RateLimiter::for('auth-ip', fn (Request $request) => Limit::perMinute(self::PER_IP_PER_MINUTE)
            ->by('ip|'.$request->ip()));

        // One limit per spelling the login could name (a local phone number
        // may be Kenyan or Congolese): every attempt counts against each, so
        // 0812… and +243812… share a budget.
        RateLimiter::for('auth-login', fn (Request $request) => array_map(
            fn (string $key) => Limit::perMinute(self::PER_LOGIN_PER_MINUTE)->by('login|'.$key),
            LoginIdentifier::throttleKeys((string) $request->input('login', '')),
        ));
    }

    public function isLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    /** Seconds until the lock ends. */
    public function retryAfter(User $user): int
    {
        return max(1, (int) ceil(now()->diffInSeconds($user->locked_until, true)));
    }

    /**
     * Count a failure atomically; the fifth locks the account and starts a
     * fresh count for when the lock ends.
     */
    public function recordFailure(User $user): void
    {
        $row = DB::selectOne(
            'update users set
                failed_sign_ins = case when failed_sign_ins + 1 >= ? then 0 else failed_sign_ins + 1 end,
                locked_until = case when failed_sign_ins + 1 >= ? then ? else locked_until end
             where id = ?
             returning failed_sign_ins, locked_until',
            [self::MAX_FAILURES, self::MAX_FAILURES, now()->addMinutes(self::LOCK_MINUTES)->format('Y-m-d H:i:s.uP'), $user->id],
        );

        if ($row !== null) {
            $user->forceFill(['failed_sign_ins' => $row->failed_sign_ins, 'locked_until' => $row->locked_until])->syncOriginal();
        }
    }
}
