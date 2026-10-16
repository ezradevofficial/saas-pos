<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Audit\AuditContext;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
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
 * Any failure leaves no tenant behind and answers null (401).
 */
class ApiKeyAuthenticator
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ApiKeyContext $context,
        private readonly AuditContext $audit,
    ) {}

    public function authenticate(Request $request, string $plain): ?User
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
            && $key->matchesSecret($token->secret, $now)
            && $key->isUsable($now)
            && $principal?->isServicePrincipal() === true
            && Tenant::query()->whereKey($tenantId)->value('status') === 'active';

        if (! $valid) {
            $this->tenants->set(null);

            return null;
        }

        if (ApiScopes::resourceOf($request->route()) === null) {
            $this->tenants->set(null);

            throw new ApiException(403, 'api_key_route_refused', __('api.errors.route_refused'));
        }

        $this->context->set($key);
        // AUD-02: every entry written with the key names it (its principal is the entry's user).
        $this->audit->setMetadata(['api_key_id' => $key->id]);

        return $principal->withAccessToken($key);
    }
}
