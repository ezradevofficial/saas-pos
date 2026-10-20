<?php

namespace Tests\Feature\Core\Platform;

use App\Core\Platform\Models\PlatformUser;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * ADM-07: every console endpoint answers each staff role as the role's
 * permissions say. A role without the permission is refused (403; 404
 * where a route-bound catalogue row is looked up first), a role with it is
 * never refused for its role. Every console route must be in the map.
 */
class PlatformPermissionMatrixTest extends TestCase
{
    use CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private const ID = '00000000-0000-7000-8000-000000000000';

    /** "METHOD uri" (after api/v1/platform/) => the permission it needs. */
    private const MAP = [
        'GET billing-settings' => 'platform.billing.view',
        'PUT billing-settings' => 'platform.billing.manage',
        'GET catalogue/annual-discounts' => 'platform.catalogue.view',
        'POST catalogue/annual-discounts' => 'platform.catalogue.manage',
        'POST catalogue/annual-discounts/{annual_discount}/archive' => 'platform.catalogue.manage',
        'GET catalogue/bundles' => 'platform.catalogue.view',
        'POST catalogue/bundles' => 'platform.catalogue.manage',
        'PATCH catalogue/bundles/{catalogue_bundle}' => 'platform.catalogue.manage',
        'POST catalogue/bundles/{catalogue_bundle}/archive' => 'platform.catalogue.manage',
        'POST catalogue/bundles/{catalogue_bundle}/restore' => 'platform.catalogue.manage',
        'GET catalogue/coupons' => 'platform.catalogue.view',
        'POST catalogue/coupons' => 'platform.catalogue.manage',
        'PATCH catalogue/coupons/{catalogue_coupon}' => 'platform.catalogue.manage',
        'POST catalogue/coupons/{catalogue_coupon}/archive' => 'platform.catalogue.manage',
        'GET catalogue/modules' => 'platform.catalogue.view',
        'POST catalogue/modules' => 'platform.catalogue.manage',
        'PATCH catalogue/modules/{catalogue_module}' => 'platform.catalogue.manage',
        'POST catalogue/modules/{catalogue_module}/archive' => 'platform.catalogue.manage',
        'POST catalogue/modules/{catalogue_module}/restore' => 'platform.catalogue.manage',
        'GET catalogue/prices' => 'platform.catalogue.view',
        'POST catalogue/prices' => 'platform.catalogue.manage',
        'POST catalogue/prices/{catalogue_price}/archive' => 'platform.catalogue.manage',
        'POST invoices/{operator_invoice}/mark-paid' => 'platform.billing.manage',
        'GET packs' => 'platform.packs.view',
        'POST packs' => 'platform.packs.manage',
        'GET packs/current' => 'platform.packs.view',
        'GET packs/{release}' => 'platform.packs.view',
        'POST packs/{release}/approve' => 'platform.packs.approve',
        'POST packs/{release}/reject' => 'platform.packs.approve',
        'POST packs/{release}/publish' => 'platform.packs.publish',
        'POST packs/{release}/supersede' => 'platform.packs.manage',
        'GET reports/summary' => 'platform.reports.view',
        'GET reports/{report}.csv' => 'platform.reports.view',
        'GET staff' => 'platform.staff.manage',
        'POST staff' => 'platform.staff.manage',
        'PATCH staff/{staff}' => 'platform.staff.manage',
        'POST staff/{staff}/deactivate' => 'platform.staff.manage',
        'POST staff/{staff}/reactivate' => 'platform.staff.manage',
        'GET support/requests' => 'platform.support.request',
        'POST support/requests/{support_request}/cancel' => 'platform.support.request',
        'POST support/requests/{support_request}/end' => 'platform.support.request',
        'POST support/requests/{support_request}/session' => 'platform.support.request',
        'POST tenants' => 'platform.tenants.create',
        'GET tenants' => 'platform.tenants.view',
        'GET tenants/awaiting-handover' => 'platform.tenants.create',
        'GET tenants/{tenant}' => 'platform.tenants.view',
        'GET tenants/{tenant}/deals' => 'platform.billing.view',
        'POST tenants/{tenant}/deals' => 'platform.deals.manage',
        'POST tenants/{tenant}/deals/{deal}/approve' => 'platform.deals.approve',
        'POST tenants/{tenant}/deals/{deal}/reject' => 'platform.deals.approve',
        'POST tenants/{tenant}/deals/{deal}/end' => 'platform.deals.approve',
        'POST tenants/{tenant}/grace' => 'platform.billing.manage',
        'POST tenants/{tenant}/reactivate' => 'platform.tenants.manage',
        'POST tenants/{tenant}/reinvite' => 'platform.tenants.create',
        'POST tenants/{tenant}/support-requests' => 'platform.support.request',
        'POST tenants/{tenant}/suspend' => 'platform.tenants.manage',
        'POST tenants/{tenant}/trial' => 'platform.tenants.manage',
    ];

    /** Any staff member's own account and sign-in, outside the role permissions. */
    private const OWN = ['GET me', 'POST auth/sign-out', 'POST me/two-factor/totp', 'POST me/two-factor/totp/confirm', 'POST auth/sign-in', 'POST auth/two-factor', 'GET auth/setup/{token}', 'POST auth/setup/{token}'];

    /** Route-bound catalogue rows: a missing row answers 404 before the role is checked. */
    private const BOUND = ['annual_discount', 'catalogue_bundle', 'catalogue_coupon', 'catalogue_module', 'catalogue_price'];

    public function test_every_console_route_answers_each_role_as_its_permissions_say(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/platform/')) {
                continue;
            }

            $key = $route->methods()[0].' '.substr($route->uri(), strlen('api/v1/platform/'));

            if (in_array($key, self::OWN, true)) {
                continue;
            }

            $this->assertArrayHasKey($key, self::MAP, "{$key} is a console route missing from the permission matrix");
            $routes[$key] = $route;
        }

        $this->assertSame([], array_values(array_diff(array_keys(self::MAP), array_keys($routes))), 'the matrix lists routes that no longer exist');

        foreach (PlatformUser::ROLES as $role) {
            $bearer = $this->staffBearer($this->staff($role));

            foreach ($routes as $key => $route) {
                $permission = self::MAP[$key];
                $uri = '/'.preg_replace('/\{report\}/', 'mrr', $route->uri());
                $uri = preg_replace('/\{[a-z_]+\}/', self::ID, $uri);
                $status = $this->json($route->methods()[0], $uri, [], $bearer)->getStatusCode();
                $allowed = in_array($role, PlatformUser::PERMISSIONS[$permission], true);

                if ($allowed) {
                    $this->assertNotSame(403, $status, "{$role} is refused {$key}, which {$permission} allows");
                } elseif (array_intersect($route->parameterNames(), self::BOUND) !== []) {
                    $this->assertContains($status, [403, 404], "{$role} reached {$key} without {$permission}");
                } else {
                    $this->assertSame(403, $status, "{$role} reached {$key} without {$permission}");
                }
            }
        }
    }
}
