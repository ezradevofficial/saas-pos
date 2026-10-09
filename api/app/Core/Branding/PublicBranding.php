<?php

namespace App\Core\Branding;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BR-04, BR-07: the branding of a host before anyone signs in: the tenant
 * whose subdomain (`{slug}.{branding.base_domain}`) or verified custom
 * domain it is. No tenant is known yet, so the lookup is the owner-owned
 * security-definer function `app_public_branding` (ADR 002) on the
 * runtime connection: it returns only the public branding fields, never a
 * row or an id, and row-level security stays on for everything else.
 */
class PublicBranding
{
    /** BR-05: how long a TLS ask answered "no" is remembered. */
    public const NEGATIVE_SECONDS = 60;

    public function __construct(private readonly BrandAssets $assets) {}

    /** @return array{slug: ?string, host: ?string}|null what to look up for $host, or null for a host that cannot be a tenant's */
    public static function parse(?string $host): ?array
    {
        $host = Str::lower(trim((string) $host));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = rtrim($host, '.');

        if ($host === '' || strlen($host) > 253 || preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        $base = Str::lower((string) config('branding.base_domain'));

        if ($base !== '' && str_ends_with($host, '.'.$base)) {
            $slug = substr($host, 0, -strlen('.'.$base));

            return str_contains($slug, '.') ? null : ['slug' => $slug, 'host' => null];
        }

        return $base !== '' && $host === $base ? null : ['slug' => null, 'host' => $host];
    }

    /** @return array<string, mixed>|null the sign-in page's branding for $host, or null (the platform default) */
    public function forHost(?string $host): ?array
    {
        $lookup = self::parse($host);

        if ($lookup === null) {
            return null;
        }

        $row = DB::connection(TenantContext::CONNECTION)->selectOne(
            'select * from public.app_public_branding(?, ?)',
            [$lookup['slug'], $lookup['host']],
        );

        if ($row === null) {
            return null;
        }

        $theme = array_filter([
            'preset' => in_array($row->preset, ThemeCompiler::PRESETS, true) ? $row->preset : 'light',
            'colors' => is_string($row->colors) ? array_intersect_key((array) json_decode($row->colors, true), ['primary' => true, 'accent' => true]) : null,
            'sidebar' => $row->sidebar,
            'corners' => $row->corners,
            'font' => $row->font,
        ], fn ($value) => $value !== null);

        return [
            'tenant_name' => $row->tenant_name,
            'theme' => $theme,
            'tokens' => ThemeCompiler::compile($theme),
            'welcome' => $row->welcome,
            'logo_light_url' => $this->assets->urlForPath($row->logo_light),
            'logo_dark_url' => $this->assets->urlForPath($row->logo_dark),
            'favicon_url' => $this->assets->urlForPath($row->favicon),
            'background_url' => $this->assets->urlForPath($row->background),
            'hide_platform' => (bool) $row->hide_platform,
        ];
    }

    /** BR-05: whether $host is a verified custom domain of an active tenant (TLS ask). */
    public function isVerifiedDomain(?string $host): bool
    {
        $lookup = self::parse($host);

        if ($lookup === null || $lookup['host'] === null) {
            return false;
        }

        // A "no" is cached for a minute: Caddy may ask for many unknown hosts (scans),
        // and the endpoint is not rate-limited, so it must stay cheap. A "yes" is never cached:
        // an archived or lost domain stops getting certificates at once.
        $key = 'tls-ask:'.$lookup['host'];

        if (Cache::get($key) === 'no') {
            return false;
        }

        $ok = DB::connection(TenantContext::CONNECTION)->selectOne(
            'select exists(select 1 from public.app_tenant_for_verified_domain(?) as t(id)) as ok',
            [$lookup['host']],
        )->ok === true;

        if (! $ok) {
            Cache::put($key, 'no', self::NEGATIVE_SECONDS);
        }

        return $ok;
    }
}
