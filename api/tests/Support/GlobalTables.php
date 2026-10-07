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
}
