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
     * the same for every tenant. Instead of RLS, grants keep them apart:
     * only the platform role (app_platform) reaches staff tables and writes
     * the catalogue; the tenant runtime role only reads the catalogue.
     * RlsCoverageTest checks the grants and that they stay free of tenant_id.
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

    /**
     * Tenant tables whose rows live only in the operator tenant (ADR 016):
     * under row-level security like every tenant table, but a customer
     * tenant never has rows in them. The isolation suite checks that
     * customers see none of the operator's rows instead of comparing two
     * customers.
     */
    public const OPERATOR = [
        'operator_invoices' => 'the platform invoices the operator issues to its customers (SUB-03)',
        'operator_accounts' => 'the operator\'s record of each customer for the platform console',
        'operator_account_events' => 'status changes of each customer\'s account, for the revenue reports (ADM-06)',
    ];
}
