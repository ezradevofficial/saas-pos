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
 * ONB-01: a tenant's subdomain ({slug}.{branding.base_domain}), made from
 * the business name with the branding slug rules (BR-04): lower-case
 * letters, digits and hyphens, at most 63, never a reserved name.
 *
 * Squatting: a self sign-up gets its subdomain only once the owner has
 * verified the contact (VerifyController). A name that is, or starts with,
 * a protected brand (config onboarding.protected_slugs), once look-alike
 * characters are folded (0→o, 1 and l→i, rn→m, Cyrillic а→a, ...), gets the
 * neutral `shop-xxxx` instead.
 *
 * Slugs are unique across tenants (a unique index sees every row whatever
 * row-level security hides); a taken or reserved one gets a short random
 * suffix. Each try runs in a savepoint, so a clash never aborts the caller.
 */
class TenantSlugs
{
    private const ATTEMPTS = 6;

    /** Look-alike characters, folded the same way on both sides of the comparison (1, l and i are one letter). */
    private const HOMOGLYPHS = ['0' => 'o', '1' => 'i', 'l' => 'i', '|' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '8' => 'b', '9' => 'g'];

    public function assign(Tenant $tenant, string $businessName): string
    {
        $base = self::base($businessName);
        if (self::isProtected($base)) {
            $base = 'shop';
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $slug = $attempt === 0 && $base !== 'shop' && ! self::isReserved($base) ? $base : self::withSuffix($base);

            try {
                DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tenant, $slug) {
                    $tenant->forceFill(['slug' => $slug])->save();
                });

                return $slug;
            } catch (UniqueConstraintViolationException) {
                $tenant->slug = null;
            }
        }

        throw new RuntimeException('No free subdomain found for the tenant.');
    }

    /** The business name as a slug, without the suffix room; `shop` when nothing is left. */
    public static function base(string $name): string
    {
        $slug = trim(Str::limit(Str::slug(Str::ascii($name)), 50, ''), '-');

        return $slug === '' ? 'shop' : $slug;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, UpdateBrandingSettingsRequest::reserved(), true) || self::isProtected($slug);
    }

    /**
     * True when $slug, folded, is a protected brand name, starts with one
     * (`safaricom-agent`), or names one as a word (`best-mpesa-shop`).
     */
    public static function isProtected(string $slug): bool
    {
        $folded = self::fold($slug);
        $words = explode('-', $folded);
        $compact = str_replace('-', '', $folded);

        foreach (config('onboarding.protected_slugs', []) as $name) {
            $name = self::fold((string) $name);
            $name = str_replace('-', '', $name);

            if ($name !== '' && ($compact === $name || in_array($name, $words, true) || (strlen($name) >= 5 && str_starts_with($compact, $name)))) {
                return true;
            }
        }

        return false;
    }

    /** Lower-case ASCII with look-alike characters folded (Cyrillic and Greek letters through Str::ascii). */
    public static function fold(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = strtr($value, self::HOMOGLYPHS);

        return str_replace(['rn', 'vv'], ['m', 'w'], $value);
    }

    private static function withSuffix(string $base): string
    {
        return $base.'-'.Str::lower(Str::random(4));
    }
}
