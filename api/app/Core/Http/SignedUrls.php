<?php

namespace App\Core\Http;

use App\Core\Identity\Models\User;
use App\Core\Platform\SupportAccess\SupportAccess;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * Temporary signed download links (files, images, exports). RBAC-13 (review
 * L2): a link made in a support session carries its grant (`sg`, signed
 * with the rest) and ends with it: its expiry is the grant's end at the
 * latest, and CheckSupportLinks refuses it once the grant has ended.
 */
final class SignedUrls
{
    public const PARAMETER = 'sg';

    /** @param array<string, mixed> $parameters */
    public static function temporary(string $route, DateTimeInterface $expires, array $parameters = []): string
    {
        $user = request()->user();
        $grant = $user instanceof User && $user->support_grant_id !== null ? app(SupportAccess::class)->grantOf($user) : null;

        if ($grant !== null && $grant->expires_at !== null) {
            $parameters[self::PARAMETER] = app(TenantContext::class)->require().'.'.$grant->id;
            $expires = CarbonImmutable::instance($expires)->min(CarbonImmutable::instance($grant->expires_at));
        }

        return URL::temporarySignedRoute($route, $expires, $parameters);
    }
}
