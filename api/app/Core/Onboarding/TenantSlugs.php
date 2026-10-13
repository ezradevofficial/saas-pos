<?php

namespace App\Core\Onboarding;

use App\Core\Branding\Http\Requests\UpdateBrandingSettingsRequest;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ONB-01: a new tenant's subdomain ({slug}.{branding.base_domain}), made
 * from the business name with the branding slug rules (BR-04): lower-case
 * letters, digits and hyphens, at most 63, never a reserved name. Slugs
 * are unique across tenants (a unique index sees every row whatever
 * row-level security hides); a taken one gets a short random suffix. Each
 * try runs in a savepoint, so a clash never aborts the sign-up.
 */
class TenantSlugs
{
    private const ATTEMPTS = 6;

    public function assign(Tenant $tenant, string $businessName): string
    {
        $base = self::base($businessName);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $slug = $attempt === 0 && ! self::isReserved($base) ? $base : self::withSuffix($base);

            try {
                DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tenant, $slug) {
                    $tenant->forceFill(['slug' => $slug])->save();
                });

                return $slug;
            } catch (UniqueConstraintViolationException) {
                $tenant->slug = null;
            }
        }

        throw new RuntimeException('No free subdomain found for the new tenant.');
    }

    /** The business name as a slug, without the suffix room; `shop` when nothing is left. */
    public static function base(string $name): string
    {
        $slug = trim(Str::limit(Str::slug(Str::ascii($name)), 50, ''), '-');

        return $slug === '' ? 'shop' : $slug;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, UpdateBrandingSettingsRequest::reserved(), true);
    }

    private static function withSuffix(string $base): string
    {
        return $base.'-'.Str::lower(Str::random(4));
    }
}
