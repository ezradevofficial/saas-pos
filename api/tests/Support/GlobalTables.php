<?php

namespace Tests\Support;

/**
 * Tables with a tenant_id that are deliberately global (no row-level
 * security): they are read before the tenant is known (bearer token
 * lookup, sign-up and sign-in codes) and are only touched by dedicated
 * services. See docs/adr/002-tenancy-rls.md. Used by RlsCoverageTest and
 * the isolation suite.
 */
final class GlobalTables
{
    public const TABLES = ['personal_access_tokens', 'verification_challenges'];

    /**
     * Platform tables (ADR 013): global by design, with no tenant_id and no
     * row-level security. Staff are not tenant users and the catalogue is
     * the same for every tenant. RlsCoverageTest checks they stay free of
     * tenant_id, so no tenant row can ever land in them.
     */
    public const PLATFORM = [
        'platform_users' => 'platform staff; they belong to no tenant',
        'platform_access_tokens' => 'staff bearer tokens, looked up by the platform guard only (never by Sanctum)',
        'platform_audit_logs' => 'one append-only hash chain of staff actions (AUD-01, AUD-03)',
        'catalogue_countries' => 'the countries sold in and their billing currency; read-only at runtime',
        'catalogue_modules' => 'the plan catalogue offered to every tenant (SUB-01)',
        'catalogue_bundles' => 'bundles offered to every tenant (SUB-01)',
        'catalogue_prices' => 'versioned list prices per country (SUB-01)',
        'catalogue_annual_discounts' => 'the annual discount as data (SUB-01)',
        'catalogue_coupons' => 'coupons, created by platform staff (SUB-01)',
    ];
}
