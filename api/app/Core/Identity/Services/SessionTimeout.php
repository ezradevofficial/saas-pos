<?php

namespace App\Core\Identity\Services;

use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Tenancy\Models\Tenant;

/** AUTH-09: back-office sessions end after the tenant's idle timeout (15–480 minutes). */
final class SessionTimeout
{
    public const MIN = 15;

    public const MAX = 480;

    public const DEFAULT = 60;

    /** The tenant's timeout, clamped to 15–480 minutes. */
    public static function minutes(?Tenant $tenant): int
    {
        $value = filter_var($tenant?->setting('session_timeout_minutes'), FILTER_VALIDATE_INT);

        if ($value === false) {
            return self::DEFAULT;
        }

        return max(self::MIN, min(self::MAX, $value));
    }

    /**
     * Idle when the last recorded use is at least the timeout ago.
     *
     * last_used_at is written at most once per ApiKeyAwareGuard::touchSeconds()
     * (60 seconds by default, never more than 300; NFR-02), so it can lag the
     * real last request by up to that interval. The timeout is therefore
     * correct to within the interval: a session may end up to that many
     * seconds before the full timeout has passed since its real last
     * request, and never later.
     */
    public static function isIdle(PersonalAccessToken $token, ?Tenant $tenant): bool
    {
        $lastActivity = $token->last_used_at ?? $token->created_at;

        return $lastActivity === null || $lastActivity->lte(now()->subMinutes(self::minutes($tenant)));
    }
}
