<?php

namespace App\Core\Tenancy;

use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Tenant;

/**
 * TEN-06, API-05 (Phase 8 review H2): a closed tenant (an inactive sandbox
 * closed by `sandbox:expire`, an unverified sign-up pruned) is closed for
 * everyone. Sign-in, the OAuth token endpoint and every authenticated
 * request (people's sessions, API keys, app tokens, tills and attendance
 * devices) answer 403 `tenant_closed`. A suspended tenant is not closed:
 * it keeps the access ADM-01c gives it (EnforceSubscription).
 *
 * Reads the tenant's own row, so the caller must be in $tenantId's
 * context (the `tenants` table is under row-level security on its id).
 */
final class ClosedTenant
{
    public const CODE = 'tenant_closed';

    public static function is(?string $tenantId): bool
    {
        return $tenantId !== null && Tenant::query()->whereKey($tenantId)->value('status') === 'closed';
    }

    public static function exception(): ApiException
    {
        return new ApiException(403, self::CODE, __('auth.tenant_closed'));
    }

    /** Throws 403 `tenant_closed` when $tenantId is closed. */
    public static function refuse(?string $tenantId): void
    {
        if (self::is($tenantId)) {
            throw self::exception();
        }
    }
}
