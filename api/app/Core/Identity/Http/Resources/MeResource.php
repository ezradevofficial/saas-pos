<?php

namespace App\Core\Identity\Http\Resources;

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

        return [
            ...parent::toArray($request),
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'default_locale' => $tenant->default_locale,
                // BR-07: "Powered by" is hidden for this tenant (set by the platform).
                'hide_platform' => (bool) ((($tenant->settings ?? [])['branding'] ?? [])['hide_platform'] ?? false),
            ],
        ];
    }
}
