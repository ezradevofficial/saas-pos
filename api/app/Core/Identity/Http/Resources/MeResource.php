<?php

namespace App\Core\Identity\Http\Resources;

use App\Core\Platform\SupportAccess\SupportAccess;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The signed-in user's own profile (GET and PATCH me): the user plus the
 * tenant it belongs to, so the UI can show the business name and fall back
 * to the tenant's default language (L10N-01). The tenant comes from the
 * request's tenant context, never from client input.
 */
class MeResource extends UserResource
{
    public function toArray(Request $request): array
    {
        $tenant = Tenant::find(app(TenantContext::class)->require());
        // RBAC-13c: a support session's principal carries its session (the "Support session" bar).
        $grant = app(SupportAccess::class)->grantOf($this->resource);

        return [
            ...parent::toArray($request),
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'default_locale' => $tenant->default_locale,
                // BR-07: "Powered by" is hidden for this tenant (set by the platform).
                'hide_platform' => (bool) ((($tenant->settings ?? [])['branding'] ?? [])['hide_platform'] ?? false),
            ],
            'support_session' => $grant === null ? null : [
                'grant_id' => $grant->id,
                'staff_name' => $grant->staff_name,
                'mode' => $grant->mode,
                'include_sensitive' => $grant->include_sensitive,
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ],
        ];
    }
}
