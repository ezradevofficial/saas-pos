<?php

namespace Modules\Procurement\Http\Middleware;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Procurement\Portal\PortalAuth;
use Modules\Procurement\ProcurementServiceProvider;
use Modules\Procurement\Support\ProcurementError;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRC-07: the supplier portal's only guard. The bearer token must be a
 * live portal session (`pts_…`; a tenant user's token is refused, 401
 * `portal_session_invalid`). Its tenant is entered (row-level security
 * from here on), the tenant must have Procurement active (RBAC-08, 403
 * `module_inactive`), the language is the tenant's unless the browser asks
 * for another (L10N-01), and the request carries the session: its tender
 * and bidder are the only ones the portal ever reads.
 */
class PortalSessionMiddleware
{
    public const ATTRIBUTE = 'procurement_portal_session';

    public function __construct(
        private readonly PortalAuth $auth,
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->auth->resolve($request->bearerToken());

        if ($session === null) {
            throw ProcurementError::make('portal_session_invalid', status: 401);
        }

        try {
            if (! $this->modules->isActive(ProcurementServiceProvider::MODULE)) {
                throw new \App\Core\Http\ApiException(403, 'module_inactive', __('rbac.errors.module_inactive'));
            }

            $request->attributes->set(self::ATTRIBUTE, $session);
            $this->applyLocale($request);

            return $next($request);
        } finally {
            $this->tenants->set(null);
        }
    }

    private function applyLocale(Request $request): void
    {
        $preferred = $request->getPreferredLanguage(['en', 'fr']);
        $tenant = \App\Core\Tenancy\Models\Tenant::query()->find($this->tenants->id());
        $header = $request->headers->get('Accept-Language');
        app()->setLocale($header !== null && $header !== '' ? ($preferred ?? 'en') : ($tenant?->default_locale ?: 'en'));
    }
}
