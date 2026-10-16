<?php

namespace Modules\Ecommerce\Http\Middleware;

use App\Core\Branding\PublicBranding;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Ecommerce\Customers\CustomerAuth;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\EcomError;
use Symfony\Component\HttpFoundation\Response;

/**
 * ECOM-05 to ECOM-08: the storefront API's only way in. The store is named
 * by the host it is served on (`X-Shop-Host`, else the `Origin` header's
 * host; BR-04, BR-05) and its handle: the security-definer function
 * `ecom_store_tenant` returns only the id of the active tenant whose slug
 * or verified domain the host is and that has a live store with that
 * handle (ADR 002). The tenant is entered (row-level security from here
 * on) and left at the end of the request. Anything else, a tenant without
 * the module, or another tenant's handle, is 404.
 *
 * A bearer token is optional and must be a customer session of this store
 * (`ecs_…`): any other token, a tenant user's included, is refused (401
 * `customer_session_invalid`), so back-office tokens never work here
 * (ECOM-07). The language is the browser's (en or fr), else the tenant's.
 */
class ResolveShop
{
    public const STORE = 'ecommerce_store';

    public const SESSION = 'ecommerce_session';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly CustomerAuth $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $store = $this->store($request, (string) $request->route('store'));
            abort_if($store === null, 404);
            $request->attributes->set(self::STORE, $store);
            $this->locale($request);
            $token = $request->bearerToken();

            if ($token !== null && $token !== '') {
                $session = $this->auth->resolve($store, $token);

                if ($session === null) {
                    throw EcomError::make('customer_session_invalid', status: 401);
                }

                $request->attributes->set(self::SESSION, $session);
            }

            return $next($request);
        } finally {
            $this->tenants->set(null);
        }
    }

    private function store(Request $request, string $handle): ?Store
    {
        $host = $request->headers->get('X-Shop-Host');

        if (($host === null || $host === '') && ($origin = $request->headers->get('Origin')) !== null) {
            $host = parse_url($origin, PHP_URL_HOST).((string) parse_url($origin, PHP_URL_PORT) !== '' ? ':'.parse_url($origin, PHP_URL_PORT) : '');
        }

        $lookup = PublicBranding::parse($host);

        if ($lookup === null || preg_match('/^[a-z0-9]([a-z0-9-]{0,58}[a-z0-9])?$/', $handle) !== 1) {
            return null;
        }

        $tenantId = DB::connection(TenantContext::CONNECTION)->selectOne('select public.ecom_store_tenant(?, ?, ?) as tenant_id', [$lookup['slug'], $lookup['host'], $handle])?->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        $this->tenants->set($tenantId);

        if (Tenant::query()->whereKey($tenantId)->value('status') !== 'active' || ! $this->modules->isActive(EcommerceServiceProvider::MODULE)) {
            return null;
        }

        return Store::query()->where('handle', $handle)->where('status', Store::LIVE)->whereNull('archived_at')->first();
    }

    private function locale(Request $request): void
    {
        $header = $request->headers->get('Accept-Language');
        $tenant = Tenant::query()->find($this->tenants->id());
        app()->setLocale($header !== null && $header !== '' ? ($request->getPreferredLanguage(['en', 'fr']) ?? 'en') : ($tenant?->default_locale ?: 'en'));
    }
}
