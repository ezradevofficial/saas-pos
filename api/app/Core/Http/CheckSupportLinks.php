<?php

namespace App\Core\Http;

use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC-13 (review L2): a signed link made in a support session (its `sg`
 * parameter, part of the signature) works only while that grant is
 * active. Global; links without `sg` pass untouched.
 */
class CheckSupportLinks
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->query(SignedUrls::PARAMETER);

        if ($value === null) {
            return $next($request);
        }

        [$tenantId, $grantId] = array_pad(explode('.', (string) $value, 2), 2, '');

        $active = Str::isUuid($tenantId) && Str::isUuid($grantId)
            && (bool) $this->tenants->run($tenantId, fn () => SupportAccessGrant::query()->find($grantId)?->isActive());

        if (! $active) {
            throw new ApiException(403, 'support_access_ended', __('platform.support.ended'));
        }

        return $next($request);
    }
}
