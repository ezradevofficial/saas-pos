<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Audit\AuditContext;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\ClosedTenant;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API-02, API-05 (ADR 020): turns `sk_<mode>_<id>_<secret>` into the key's
 * principal.
 *
 * 1. `api_key_tenant()` (security definer, ADR 002) gives the tenant of
 *    the key id, only when the tenant's sandbox flag matches the prefix:
 *    a `test` key never reaches a live tenant, nor a `live` key a sandbox.
 * 2. In that tenant's context, under row-level security, the key is read
 *    and its secret compared in constant time (the previous secret too
 *    while a rotation overlap lasts). Revoked or expired keys, tenants that
 *    are not active and principals that are not status `service` fail.
 * 3. A key is accepted only on a route marked `public-api:<resource>`;
 *    elsewhere the request is refused with 403 `api_key_route_refused`.
 *
 * Any failure leaves no tenant behind and answers null (401); a token
 * failing too often from one address answers 429 (FailedAuthLimit). A
 * valid key of a closed tenant answers 403 `tenant_closed`.
 */
class ApiKeyAuthenticator
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ApiKeyContext $context,
        private readonly AuditContext $audit,
    ) {}

    private const FAILED = 'api_key_authentication_failed';

    public function authenticate(Request $request, string $plain): ?User
    {
        // The guard is asked again on every Auth::user() of a failed request: count the failure once.
        if ($request->attributes->get(self::FAILED) === true) {
            return null;
        }

        $principal = $this->resolve($plain);

        if ($principal === null) {
            $request->attributes->set(self::FAILED, true);
            // Review item 5, Phase 8 review M5: failures are limited per key id and address, after the lookup.
            FailedAuthLimit::fail(FailedAuthLimit::key('api-key', ApiKeyToken::parse($plain)?->publicId ?? $plain, $request->ip()));

            return null;
        }

        if (ApiScopes::resourceOf($request->route()) === null) {
            $this->tenants->set(null);

            throw new ApiException(403, 'api_key_route_refused', __('api.errors.route_refused'));
        }

        $key = $principal->currentAccessToken();
        $this->context->set($key);
        // AUD-02: every entry written with the key names it (its principal is the entry's user).
        $this->audit->setMetadata(['api_key_id' => $key->id]);

        return $principal;
    }

    /** The key's principal (with the key as its token), or null for a key that does not authenticate. */
    private function resolve(string $plain): ?User
    {
        $token = ApiKeyToken::parse($plain);

        if ($token === null) {
            return null;
        }

        $tenantId = DB::connection(TenantContext::CONNECTION)
            ->selectOne('select public.api_key_tenant(?, ?) as id', [$token->publicId, $token->isSandbox()])?->id;

        if ($tenantId === null) {
            return null;
        }

        $this->tenants->set($tenantId);
        $now = CarbonImmutable::now();
        $key = ApiKey::query()->where('public_id', $token->publicId)->first();
        $principal = $key === null ? null : User::query()->whereKey($key->principal_id)->first();

        $valid = $key !== null
            && $key->mode === $token->mode
            // ADR 024: an install's key has no secret anyone holds; it authenticates only through the app's OAuth token.
            && $key->kind === ApiKey::KIND_KEY
            && $key->matchesSecret($token->secret, $now)
            && $key->isUsable($now)
            && $principal?->isServicePrincipal() === true;
        $status = $valid ? Tenant::query()->whereKey($tenantId)->value('status') : null;

        if (! $valid || $status !== 'active') {
            $this->tenants->set(null);

            // Phase 8 review H2: a valid key of a closed tenant is told why (403 `tenant_closed`).
            if ($status === 'closed') {
                throw ClosedTenant::exception();
            }

            return null;
        }

        return $principal->withAccessToken($key);
    }
}
