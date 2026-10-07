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

    public static function isIdle(PersonalAccessToken $token, ?Tenant $tenant): bool
    {
        $lastActivity = $token->last_used_at ?? $token->created_at;

        return $lastActivity === null || $lastActivity->lte(now()->subMinutes(self::minutes($tenant)));
    }
}
