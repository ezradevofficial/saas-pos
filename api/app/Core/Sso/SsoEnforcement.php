<?php

namespace App\Core\Sso;

use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use App\Core\Sso\Domains\SsoDomains;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-11, ADR 023: enforced single sign-on.
 *
 * - Password sign-in is refused for a user of an enforcing tenant whose
 *   email domain is one of its verified domains, unless they hold the
 *   Owner role at tenant scope (break-glass, RBAC-10).
 * - Sign-up with an email of a domain verified by an enforcing tenant is
 *   refused: that person belongs to that organisation.
 */
class SsoEnforcement
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SsoSettings $settings,
        private readonly SsoDomains $domains,
        private readonly OwnerGuard $owners,
    ) {}

    /** Requires $user's tenant context. */
    public function refusesPassword(User $user): bool
    {
        return $user->email !== null
            && $this->settings->enforced()
            && $this->domains->covers($user->email)
            && ! $this->owners->holdsOwnerRole($user);
    }

    /** Whether a new account with $email must be refused. Leaves the tenant context as it was. */
    public function refusesSignUp(?string $email): bool
    {
        $domain = SsoDomains::domainOf($email);

        if ($domain === null) {
            return false;
        }

        $tenantId = DB::connection(TenantContext::CONNECTION)
            ->selectOne('select t as id from public.sso_tenant_for_email_domain(?) as t', [$domain])?->id;

        return $tenantId !== null && $this->tenants->run($tenantId, fn () => $this->settings->enforced());
    }
}
