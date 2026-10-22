<?php

namespace App\Core\Tenancy\Http;

use App\Core\Tenancy\ClosedTenant;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API group, after auth: refuse the request when no tenant was resolved
 * (TEN-01), when the user is an assisted tenant's setup account (ONB-08),
 * or when the tenant is closed (403 `tenant_closed`, Phase 8 review H2):
 * every session, API key, app token, till and attendance device of a
 * closed tenant stops, whatever its subscription.
 */
class RequireTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->context->id() === null, 401);
        // ONB-08: an assisted tenant's setup account stands for staff, who never act in a tenant.
        abort_if($request->user()?->getAttribute('is_platform_staff') === true, 401);

        if (ClosedTenant::is($this->context->id())) {
            $this->context->set(null);

            throw ClosedTenant::exception();
        }

        return $next($request);
    }
}
