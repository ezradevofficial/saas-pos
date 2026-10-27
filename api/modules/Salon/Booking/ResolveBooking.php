<?php

namespace Modules\Salon\Booking;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\SalonServiceProvider;
use Modules\Salon\Support\Errors;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAL-15: the public booking page's only way in. The branch is named by its code alone: the security-definer function
 * `salon_booking_tenant` returns only the id of the active tenant whose branch has that code and the page switched on
 * (ADR 002). The tenant is entered (row-level security from here on) and left at the end of the request. Anything else, a
 * tenant without the module or a page turned off since, is 404: no one learns which codes exist.
 */
class ResolveBooking
{
    public const BRANCH = 'salon_booking_branch';

    public function __construct(private readonly TenantContext $tenants, private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $code = strtolower((string) $request->route('code'));
            $tenantId = preg_match('/^[a-z0-9]{6,24}$/', $code) === 1
                ? DB::connection(TenantContext::CONNECTION)->selectOne('select public.salon_booking_tenant(?) as tenant_id', [$code])?->tenant_id
                : null;

            abort_if($tenantId === null, 404);
            $this->tenants->set($tenantId);
            $branch = Tenant::query()->whereKey($tenantId)->value('status') === 'active' && $this->modules->isActive(SalonServiceProvider::MODULE)
                ? SalonBranch::query()->where('public_code', $code)->where('online_enabled', true)->first()
                : null;
            abort_if($branch === null, 404);

            $request->attributes->set(self::BRANCH, $branch);
            $header = $request->headers->get('Accept-Language');
            app()->setLocale($header !== null && $header !== '' ? ($request->getPreferredLanguage(['en', 'fr']) ?? 'en') : (Tenant::query()->find($tenantId)?->default_locale ?: 'en'));

            return $next($request);
        } finally {
            $this->tenants->set(null);
        }
    }

    public static function branch(Request $request): SalonBranch
    {
        return $request->attributes->get(self::BRANCH) ?? throw Errors::make(404, 'booking_unknown');
    }
}
