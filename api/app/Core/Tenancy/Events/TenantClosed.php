<?php

namespace App\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * TEN-06: a tenant's status became `closed`, by whichever path (sandbox
 * expiry, a pruned sign-up, and any later staff or dunning close). Raised by
 * the Tenant model when the change is saved, inside the closing transaction
 * and the tenant's context, so listeners commit or roll back with it. The
 * closing export listens (EXP-02, TenantExports::requestOnClosing).
 */
class TenantClosed
{
    use Dispatchable;

    public function __construct(public readonly string $tenantId) {}
}
