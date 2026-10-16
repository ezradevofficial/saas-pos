<?php

namespace App\Core\Platform\SupportAccess\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Platform\SupportAccess\Http\Requests\EndSupportSessionRequest;
use App\Core\Platform\SupportAccess\Http\Requests\ExchangeSupportSessionRequest;
use App\Core\Platform\SupportAccess\SupportAccess;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * RBAC-13c: the tenant web's side of a support session. The console's
 * one-time entry code becomes the session's token (public, rate-limited:
 * the code is the credential); the principal ends its own session.
 */
class SupportSessionController
{
    public function __construct(
        private readonly SupportAccess $support,
        private readonly TenantContext $tenants,
    ) {}

    public function exchange(ExchangeSupportSessionRequest $request): JsonResponse
    {
        $result = $this->support->exchange((string) $request->validated('code'), (string) $request->ip(), (string) $request->userAgent());
        // Nothing of the exchange stays on this request.
        $this->tenants->set(null);

        if ($result === null) {
            throw new ApiException(404, 'support_entry_code_invalid', __('platform.support.entry_code_invalid'));
        }

        $grant = $result['grant'];

        return response()->json(['data' => [
            'token' => $result['token'],
            'tenant' => ['id' => $result['tenant']->id, 'name' => $result['tenant']->name],
            'support_session' => [
                'grant_id' => $grant->id,
                'staff_name' => $grant->staff_name,
                'mode' => $grant->mode,
                'include_sensitive' => $grant->include_sensitive,
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ],
        ]]);
    }

    public function end(EndSupportSessionRequest $request): JsonResponse
    {
        /** @var User $principal */
        $principal = $request->user();
        $grant = $this->support->endInSession($principal);

        return response()->json(['data' => ['status' => $grant->status, 'ended_at' => $grant->ended_at?->toIso8601String()]]);
    }
}
