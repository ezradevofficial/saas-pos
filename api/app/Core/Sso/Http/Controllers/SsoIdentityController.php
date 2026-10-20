<?php

namespace App\Core\Sso\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Sso\Http\Requests\MySsoRequest;
use App\Core\Sso\Http\Requests\UserSsoIdentitiesRequest;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoIdentity;
use App\Core\Sso\SsoFlows;
use App\Core\Sso\SsoIdentities;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-11, ADR 023: SSO links.
 *
 * - GET me/sso: the enabled connections and the user's own links.
 * - POST me/sso/link: the user links their own account (the only way an
 *   Owner is linked); answers the IdP URL, back to Settings, Security.
 * - POST me/sso/identities/{sso_identity}/archive: unlink one's own.
 * - GET users/{user}/sso-identities and POST
 *   users/{user}/sso-identities/{sso_identity}/archive: an SSO manager
 *   sees and unlinks a user's links.
 *
 * Unlinks are audited as `auth.sso.unlink`.
 */
class SsoIdentityController
{
    public function __construct(private readonly SsoIdentities $identities) {}

    public function mine(MySsoRequest $request): JsonResponse
    {
        $connections = SsoConnection::query()->where('enabled', true)->whereNull('archived_at')->orderBy('name')->get();

        return response()->json(['data' => [
            'connections' => $connections->map(fn (SsoConnection $c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider])->values(),
            'identities' => $this->identities->of($request->user())->map(fn (SsoIdentity $i) => SsoIdentities::present($i))->values(),
        ]]);
    }

    public function link(MySsoRequest $request, SsoFlows $flows): JsonResponse
    {
        $started = $flows->startLink($request->connection(), $request->user(), $request->validated('origin'));

        return response()->json(['data' => ['redirect_url' => $started['redirect_url']]]);
    }

    public function unlinkMine(MySsoRequest $request): JsonResponse
    {
        $this->identities->unlink($request->identity(), 'self');

        return response()->json(['data' => null]);
    }

    public function ofUser(UserSsoIdentitiesRequest $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->identities->of($request->target())->map(fn (SsoIdentity $i) => SsoIdentities::present($i))->values()]);
    }

    public function unlink(UserSsoIdentitiesRequest $request, User $user): JsonResponse
    {
        $this->identities->unlink($request->identity(), 'manager');

        return response()->json(['data' => null]);
    }
}
